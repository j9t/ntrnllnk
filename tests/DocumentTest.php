<?php
/**
 * Tests for Document
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Ntrnllnk\Document;
use Ntrnllnk\Tokenizer;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase {

	public function test_from_post_weights_title_and_headings(): void {
		$document = Document::from_post(
			7,
			'Brain',
			'<h2>Memory</h2><p>Brain and memory</p>',
			[ 3, 5 ],
			1700000000,
			new Tokenizer( 'en' )
		);

		$this->assertSame( 7, $document->id );
		$this->assertSame(
			[
				'brain'  => 4,
				'memory' => 3,
			],
			$document->words
		);
		$this->assertSame( [ 3, 5 ], $document->terms );
		$this->assertSame( 1700000000, $document->time );
	}

	public function test_from_post_collects_links(): void {
		$document = Document::from_post(
			1,
			'Title',
			'<p><a href="https://www.example.com/books/3570552233/">Incognito</a></p>',
			[],
			0,
			new Tokenizer( 'en' )
		);

		$this->assertSame( [ 'example.com/books/3570552233' ], $document->links );
	}
}