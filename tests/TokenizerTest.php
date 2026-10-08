<?php
/**
 * Tests for Tokenizer
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Ntrnllnk\Tokenizer;
use PHPUnit\Framework\TestCase;

final class TokenizerTest extends TestCase {

	public function test_tokens_drops_stopwords_short_words_and_numbers(): void {
		$tokenizer = new Tokenizer( 'de' );
		$this->assertSame( [ 'gehirn', 'wiegt', 'kilogramm' ], $tokenizer->tokens( 'Das Gehirn wiegt 1,5 Kilogramm, ja.' ) );
	}

	public function test_tokens_keeps_repeats(): void {
		$tokenizer = new Tokenizer( 'de' );
		$this->assertSame( [ 'krimi', 'krimi' ], $tokenizer->tokens( 'Krimi, Krimis' ) );
	}

	public function test_tokens_splits_on_hyphens_and_apostrophes(): void {
		$tokenizer = new Tokenizer( 'en' );
		$this->assertSame( [ 'science', 'fiction', 'author' ], $tokenizer->tokens( 'Science-fiction author’s' ) );
	}

	public function test_stem_folds_german_umlauts_and_plurals(): void {
		$tokenizer = new Tokenizer( 'de' );
		$this->assertSame( 'buch', $tokenizer->stem( 'bücher' ) );
		$this->assertSame( 'buch', $tokenizer->stem( 'buch' ) );
		$this->assertSame( 'gehirn', $tokenizer->stem( 'gehirns' ) );
		$this->assertSame( 'gehirn', $tokenizer->stem( 'gehirnen' ) );
		$this->assertSame( $tokenizer->stem( 'strassen' ), $tokenizer->stem( 'straße' ) );
		$this->assertSame( 'gedachtnis', $tokenizer->stem( 'gedächtnis' ) );
		$this->assertSame( 'geheimnis', $tokenizer->stem( 'geheimnisse' ) );
	}

	public function test_stem_keeps_short_german_stems(): void {
		$tokenizer = new Tokenizer( 'de' );
		$this->assertSame( 'rose', $tokenizer->stem( 'rose' ) );
	}

	public function test_stem_strips_english_suffixes(): void {
		$tokenizer = new Tokenizer( 'en' );
		$this->assertSame( 'book', $tokenizer->stem( 'books' ) );
		$this->assertSame( 'story', $tokenizer->stem( 'stories' ) );
		$this->assertSame( 'read', $tokenizer->stem( 'reading' ) );
		$this->assertSame( 'class', $tokenizer->stem( 'class' ) );
	}

	public function test_unknown_language_only_lowercases(): void {
		$tokenizer = new Tokenizer( 'fr' );
		$this->assertSame( [ 'les', 'livres' ], $tokenizer->tokens( 'Les Livres' ) );
	}
}