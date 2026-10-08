<?php
/**
 * Builds the plugin into `dist/`: `dist/ntrnllnk/` with the files for the server, and `dist/ntrnllnk.zip`
 *
 * Builds from the working copy, so that uncommitted changes can be tried, with the same rules as `git archive`: without what `.gitignore` ignores and `.gitattributes` marks `export-ignore`.
 *
 * @package Ntrnllnk
 */

declare( strict_types = 1 );

const NTRNLLNK_NAME = 'ntrnllnk';

/**
 * Files that must not end up in a build
 */
const NTRNLLNK_FILES_DEV = [ '.git', 'bin', 'composer.json', 'composer.lock', 'phpunit.xml.dist', 'tests', 'vendor' ];

/**
 * Runs a command in the project root and returns its output
 *
 * @param string[]              $command Command and arguments.
 * @param array<string, string> $env     Additional environment variables.
 * @phpstan-param list<string> $command
 * @throws RuntimeException If the command fails.
 */
function ntrnllnk_run( array $command, array $env = [] ): string {
	$process = proc_open(
		$command,
		[
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		],
		$pipes,
		dirname( __DIR__ ),
		$env ? array_merge( getenv(), $env ) : null
	);
	if ( false === $process ) {
		throw new RuntimeException( 'Could not run ' . $command[0] );
	}
	$output = (string) stream_get_contents( $pipes[1] );
	$errors = (string) stream_get_contents( $pipes[2] );
	if ( 0 !== proc_close( $process ) ) {
		throw new RuntimeException( implode( ' ', $command ) . ' failed: ' . trim( $errors ) );
	}

	return $output;
}

/**
 * Deletes a directory with all its contents
 *
 * @param string $dir Directory.
 */
function ntrnllnk_delete( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $entries as $entry ) {
		$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $dir );
}

/**
 * Builds the plugin and checks the result
 *
 * @throws RuntimeException If a step or check fails.
 * @return string Summary.
 */
function ntrnllnk_build(): string {
	$dir_dist = dirname( __DIR__ ) . '/dist';
	$file_zip = $dir_dist . '/' . NTRNLLNK_NAME . '.zip';

	ntrnllnk_delete( $dir_dist );
	mkdir( $dir_dist );

	// A temporary index snapshots the working copy without touching the real one
	$index = tempnam( sys_get_temp_dir(), NTRNLLNK_NAME );
	unlink( $index );
	try {
		ntrnllnk_run( [ 'git', 'add', '--all' ], [ 'GIT_INDEX_FILE' => $index ] );
		$tree = trim( ntrnllnk_run( [ 'git', 'write-tree' ], [ 'GIT_INDEX_FILE' => $index ] ) );
	} finally {
		if ( is_file( $index ) ) {
			unlink( $index );
		}
	}
	ntrnllnk_run( [ 'git', 'archive', '--format=zip', '--prefix=' . NTRNLLNK_NAME . '/', '--output=' . $file_zip, $tree ] );

	$zip = new ZipArchive();
	if ( true !== $zip->open( $file_zip ) || ! $zip->extractTo( $dir_dist ) ) {
		throw new RuntimeException( 'Could not extract ' . $file_zip );
	}
	$zip->close();

	// Checks
	$dir_plugin = $dir_dist . '/' . NTRNLLNK_NAME;
	$main       = (string) file_get_contents( $dir_plugin . '/' . NTRNLLNK_NAME . '.php' );
	if ( ! preg_match( '/^ \* Plugin Name: +' . NTRNLLNK_NAME . '$/m', $main ) ) {
		throw new RuntimeException( 'Main plugin file or its header is missing' );
	}
	foreach ( NTRNLLNK_FILES_DEV as $file ) {
		if ( file_exists( $dir_plugin . '/' . $file ) ) {
			throw new RuntimeException( 'Development file in build: ' . $file );
		}
	}
	$count = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir_plugin, FilesystemIterator::SKIP_DOTS ) ) as $entry ) {
		++$count;
		if ( 'php' === $entry->getExtension() ) {
			ntrnllnk_run( [ PHP_BINARY, '-l', $entry->getPathname() ] );
		}
	}

	return sprintf( 'Built %d files into dist/%s/ and dist/%s.zip', $count, NTRNLLNK_NAME, NTRNLLNK_NAME );
}

try {
	fwrite( STDOUT, ntrnllnk_build() . "\n" );
} catch ( RuntimeException $err ) {
	fwrite( STDERR, $err->getMessage() . "\n" );
	exit( 1 );
}