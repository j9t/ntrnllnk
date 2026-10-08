<?php
/**
 * Tests for Language
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk\Tests;

use Ntrnllnk\Language;
use PHPUnit\Framework\TestCase;

final class LanguageTest extends TestCase {

	public function test_detect_recognizes_german(): void {
		$this->assertSame( 'de', Language::detect( 'Das ist ein Buch über die Geschichte der Wikinger, und es ist sehr gut.', 'en' ) );
	}

	public function test_detect_recognizes_english(): void {
		$this->assertSame( 'en', Language::detect( 'This is a book about the history of the Vikings, and it is very good.', 'de' ) );
	}

	public function test_detect_falls_back_without_stopwords(): void {
		$this->assertSame( 'de', Language::detect( 'Lorem ipsum dolor sit amet', 'de' ) );
	}

	public function test_detect_falls_back_on_tie(): void {
		$this->assertSame( 'en', Language::detect( 'und the', 'en' ) );
	}

	public function test_detect_falls_back_on_weak_evidence(): void {
		$this->assertSame( 'de', Language::detect( 'Brandon Sanderson: The Way of Kings', 'de' ) );
	}

	public function test_detect_handles_empty_text(): void {
		$this->assertSame( 'en', Language::detect( '', 'en' ) );
	}
}