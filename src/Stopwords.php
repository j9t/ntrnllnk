<?php
/**
 * Stopwords per language
 *
 * @package Ntrnllnk
 */

namespace Ntrnllnk;

defined( 'ABSPATH' ) || exit;

/**
 * Stopwords per language, lowercase; words shorter than three characters are dropped anyway
 */
final class Stopwords {

	private const DE = [ 'aber', 'alle', 'allem', 'allen', 'aller', 'alles', 'als', 'also', 'andere', 'anderem', 'anderen', 'anderer', 'anderes', 'auch', 'auf', 'aus', 'außerdem', 'bei', 'beim', 'bereits', 'bin', 'bis', 'bisher', 'bist', 'dabei', 'dafür', 'daher', 'damit', 'dann', 'darauf', 'darin', 'darum', 'das', 'dass', 'davon', 'dein', 'deine', 'dem', 'den', 'denen', 'denn', 'der', 'deren', 'des', 'deshalb', 'dessen', 'dich', 'die', 'dies', 'diese', 'diesem', 'diesen', 'dieser', 'dieses', 'dir', 'doch', 'dort', 'durch', 'eher', 'ein', 'eine', 'einem', 'einen', 'einer', 'eines', 'einige', 'einmal', 'erst', 'etwa', 'etwas', 'euch', 'euer', 'für', 'ganz', 'gegen', 'gegenüber', 'geht', 'gewesen', 'gibt', 'hab', 'habe', 'haben', 'hat', 'hatte', 'hatten', 'heute', 'hier', 'hin', 'hinter', 'ich', 'ihm', 'ihn', 'ihnen', 'ihr', 'ihre', 'ihrem', 'ihren', 'ihrer', 'ihres', 'immer', 'indem', 'innerhalb', 'ins', 'ist', 'jede', 'jedem', 'jeden', 'jeder', 'jedes', 'jedoch', 'jene', 'jetzt', 'kann', 'kaum', 'kein', 'keine', 'keinem', 'keinen', 'keiner', 'können', 'könnte', 'lässt', 'mal', 'man', 'manche', 'mehr', 'mein', 'meine', 'meist', 'mich', 'mir', 'mit', 'muss', 'musste', 'nach', 'neben', 'nicht', 'nichts', 'noch', 'nun', 'nur', 'oder', 'oft', 'ohne', 'schon', 'sehr', 'sei', 'seien', 'sein', 'seine', 'seinem', 'seinen', 'seiner', 'seit', 'selbst', 'sich', 'sie', 'sind', 'sogar', 'solche', 'soll', 'sollte', 'sondern', 'sonst', 'sowie', 'statt', 'trotz', 'über', 'und', 'uns', 'unser', 'unter', 'viel', 'vom', 'von', 'vor', 'während', 'war', 'waren', 'wäre', 'wären', 'warum', 'was', 'weil', 'weiter', 'welche', 'welchem', 'welchen', 'welcher', 'welches', 'wem', 'wen', 'wenn', 'wer', 'werde', 'werden', 'wie', 'wieder', 'will', 'wir', 'wird', 'wirst', 'wollen', 'worden', 'wurde', 'wurden', 'würde', 'würden', 'zudem', 'zum', 'zur', 'zwar', 'zwischen' ];

	private const EN = [ 'about', 'above', 'after', 'again', 'against', 'all', 'also', 'and', 'any', 'are', 'because', 'been', 'before', 'being', 'below', 'between', 'both', 'but', 'can', 'could', 'did', 'does', 'doing', 'down', 'during', 'each', 'even', 'few', 'for', 'from', 'further', 'get', 'gets', 'got', 'had', 'has', 'have', 'having', 'her', 'here', 'hers', 'herself', 'him', 'himself', 'his', 'how', 'however', 'into', 'its', 'itself', 'just', 'like', 'made', 'make', 'makes', 'many', 'may', 'might', 'more', 'most', 'much', 'must', 'myself', 'new', 'nor', 'not', 'now', 'off', 'once', 'one', 'only', 'other', 'our', 'ours', 'ourselves', 'out', 'over', 'own', 'same', 'she', 'should', 'some', 'still', 'such', 'than', 'that', 'the', 'their', 'theirs', 'them', 'themselves', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'too', 'two', 'under', 'until', 'very', 'was', 'way', 'ways', 'well', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'whom', 'why', 'will', 'with', 'would', 'yet', 'you', 'your', 'yours', 'yourself', 'yourselves' ];

	/**
	 * Returns the stopwords of a language as a lookup table
	 *
	 * @param string $language ISO 639-1 code, e.g., `de`.
	 * @return array<string, true>
	 */
	public static function for_language( string $language ): array {
		$words = match ( $language ) {
			'de' => self::DE,
			'en' => self::EN,
			default => [],
		};

		return array_fill_keys( $words, true );
	}
}