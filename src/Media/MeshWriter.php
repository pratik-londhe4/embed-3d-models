<?php
/**
 * Streams triangles into an interleaved vertex buffer.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Collects triangles as position + normal pairs (24 bytes per vertex,
 * little-endian float32) in a file, tracking the bounds glTF requires.
 *
 * Memory stays flat however large the model is: vertices are written out
 * every megabyte.
 */
final class MeshWriter {

	/**
	 * Bytes per vertex: three floats of position and three of normal.
	 */
	public const STRIDE = 24;

	/**
	 * Output file.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Bytes not yet written.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Vertices written.
	 *
	 * @var int
	 */
	private int $vertices = 0;

	/**
	 * Smallest x, y, z.
	 *
	 * @var float[]
	 */
	private array $min = array( INF, INF, INF );

	/**
	 * Largest x, y, z.
	 *
	 * @var float[]
	 */
	private array $max = array( -INF, -INF, -INF );

	/**
	 * Whether to turn Z-up coordinates into glTF's Y-up.
	 *
	 * @var bool
	 */
	private bool $z_up;

	/**
	 * Scale applied to positions.
	 *
	 * @var float
	 */
	private float $scale;

	/**
	 * Whether a write failed.
	 *
	 * @var bool
	 */
	private bool $failed = false;

	/**
	 * Sets up the writer.
	 *
	 * @param resource $handle Output file, opened for writing.
	 * @param bool     $z_up   Source uses Z as up.
	 * @param float    $scale  Position scale (0.001 turns millimetres into metres).
	 */
	public function __construct( $handle, bool $z_up, float $scale ) {
		$this->handle = $handle;
		$this->z_up   = $z_up;
		$this->scale  = $scale;
	}

	/**
	 * Adds a triangle. Normals are computed from the corners unless all three
	 * are given (OBJ `vn`).
	 *
	 * @param float[]      $a       First corner, x y z.
	 * @param float[]      $b       Second corner.
	 * @param float[]      $c       Third corner.
	 * @param float[]|null $normals Nine floats (one normal per corner) or null.
	 */
	public function triangle( array $a, array $b, array $c, ?array $normals = null ): bool {
		$a = $this->position( $a );
		$b = $this->position( $b );
		$c = $this->position( $c );
		if ( null === $a || null === $b || null === $c ) {
			return false;
		}

		if ( null !== $normals && 9 === count( $normals ) ) {
			$na = $this->direction( array_slice( $normals, 0, 3 ) );
			$nb = $this->direction( array_slice( $normals, 3, 3 ) );
			$nc = $this->direction( array_slice( $normals, 6, 3 ) );
		} else {
			$na = self::face_normal( $a, $b, $c );
			$nb = $na;
			$nc = $na;
		}

		$this->buffer   .= pack( 'g6', $a[0], $a[1], $a[2], $na[0], $na[1], $na[2] )
			. pack( 'g6', $b[0], $b[1], $b[2], $nb[0], $nb[1], $nb[2] )
			. pack( 'g6', $c[0], $c[1], $c[2], $nc[0], $nc[1], $nc[2] );
		$this->vertices += 3;

		if ( strlen( $this->buffer ) >= 1048576 ) {
			$this->flush();
		}

		return true;
	}

	/**
	 * Writes what is left and returns the totals.
	 *
	 * @return array{vertices: int, min: float[], max: float[], ok: bool}
	 */
	public function finish(): array {
		$this->flush();

		return array(
			'vertices' => $this->vertices,
			'min'      => array_map( array( self::class, 'float32' ), $this->min ),
			'max'      => array_map( array( self::class, 'float32' ), $this->max ),
			'ok'       => ! $this->failed,
		);
	}

	/**
	 * The unit normal of a triangle, or straight up for a degenerate one.
	 *
	 * @param float[] $a First corner.
	 * @param float[] $b Second corner.
	 * @param float[] $c Third corner.
	 * @return float[]
	 */
	public static function face_normal( array $a, array $b, array $c ): array {
		$u = array( $b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2] );
		$v = array( $c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2] );

		return self::normalize(
			array(
				$u[1] * $v[2] - $u[2] * $v[1],
				$u[2] * $v[0] - $u[0] * $v[2],
				$u[0] * $v[1] - $u[1] * $v[0],
			)
		);
	}

	/**
	 * Rounds a number to the nearest float32, as stored in the buffer.
	 *
	 * Rounding is monotonic, so the float32 of the smallest double is the
	 * smallest float32: accessor min and max match the buffer exactly.
	 *
	 * @param float $value Number.
	 */
	public static function float32( float $value ): float {
		$unpacked = unpack( 'gv', pack( 'g', $value ) );

		return is_array( $unpacked ) ? (float) $unpacked['v'] : $value;
	}

	/**
	 * Transforms a corner and widens the bounds.
	 *
	 * @param float[] $p Corner from the file.
	 * @return float[]|null Null when not three finite numbers.
	 */
	private function position( array $p ): ?array {
		if ( 3 !== count( $p ) ) {
			return null;
		}
		$p = $this->orient( array_values( $p ) );
		foreach ( $p as $i => $value ) {
			$value = $value * $this->scale;
			if ( ! is_finite( $value ) ) {
				return null;
			}
			$p[ $i ]         = $value;
			$this->min[ $i ] = min( $this->min[ $i ], $value );
			$this->max[ $i ] = max( $this->max[ $i ], $value );
		}

		return $p;
	}

	/**
	 * Transforms a normal from the file.
	 *
	 * @param float[] $n Normal.
	 * @return float[]
	 */
	private function direction( array $n ): array {
		return self::normalize( $this->orient( array_values( $n ) ) );
	}

	/**
	 * Turns Z-up into Y-up: (x, y, z) becomes (x, z, -y), a rotation that keeps the winding.
	 *
	 * @param float[] $p Vector.
	 * @return float[]
	 */
	private function orient( array $p ): array {
		$p = array_map( 'floatval', $p );

		return $this->z_up ? array( $p[0], $p[2], -$p[1] ) : $p;
	}

	/**
	 * Scales a vector to length one, or straight up when it has no length.
	 *
	 * @param float[] $n Vector.
	 * @return float[]
	 */
	private static function normalize( array $n ): array {
		$length = sqrt( $n[0] * $n[0] + $n[1] * $n[1] + $n[2] * $n[2] );
		if ( ! is_finite( $length ) || $length < 1e-12 ) {
			return array( 0.0, 1.0, 0.0 );
		}

		return array( $n[0] / $length, $n[1] / $length, $n[2] / $length );
	}

	/**
	 * Writes the buffer out.
	 */
	private function flush(): void {
		if ( ! Stream::write( $this->handle, $this->buffer ) ) {
			$this->failed = true;
		}
		$this->buffer = '';
	}
}
