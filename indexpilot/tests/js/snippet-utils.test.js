const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const utils = require( '../../assets/js/snippet-utils.js' );

// Условная ширина: 10 px на символ.
const measure = ( text ) => text.length * 10;

test( 'canonical допускает пустое поле и другой сайт, предупреждает о ключевых словах и обрывках URL', () => {
	const site = 'https://hs-manacost.ru/';
	for ( const value of [ '', site, 'https://kolodahearthstone.com/гайд/', 'https://пример.рф/гайд' ] ) {
		assert.equal( utils.canonicalLooksInvalid( value, site ), false, value );
	}
	for ( const value of [ 'Рено, Ульдум', '/teorikraft/', 'http://spell-tempo-mag-yarmarka-bezumiya', 'http://wow%20classic,%20карты', 'https://login@example.org/' ] ) {
		assert.equal( utils.canonicalLooksInvalid( value, site ), true, value );
	}
	assert.equal( utils.canonicalLooksInvalid( 'http://localhost/guide', 'http://localhost/' ), false );
} );

test( 'заголовок, который помещается, не меняется', () => {
	assert.equal( utils.truncateToWidth( 'Колода мага', 580, measure ), 'Колода мага' );
} );

test( 'заголовок шире предела обрезается по слову с многоточием', () => {
	const title = 'Колода мага на заклинаниях: подробный гайд для новичков и опытных игроков сезона';
	const result = utils.truncateToWidth( title, 580, measure );

	assert.ok( measure( result ) <= 580, 'результат помещается в ширину' );
	assert.ok( result.endsWith( ' …' ) );
	assert.ok( title.startsWith( result.slice( 0, -2 ) ), 'результат — начало исходного заголовка' );
	assert.equal( title.charAt( result.length - 2 ), ' ', 'обрезка прошла по пробелу' );
} );

test( 'описание обрезается по числу символов и по слову', () => {
	const text = 'Колода мага на заклинаниях держит темп с первых ходов и наказывает медленные архетипы.';
	const result = utils.truncateChars( text, 40 );

	assert.ok( result.length <= 40 );
	assert.ok( result.endsWith( '…' ) );
	assert.equal( utils.truncateChars( 'Коротко.', 40 ), 'Коротко.' );
} );

test( 'адрес превращается в цепочку', () => {
	assert.equal( utils.breadcrumb( 'https://hs-manacost.ru/category/gajdy/' ), 'https://hs-manacost.ru › category › gajdy' );
	assert.equal( utils.breadcrumb( 'https://hs-manacost.ru/' ), 'https://hs-manacost.ru' );
	assert.equal( utils.breadcrumb( 'https://site.ru/%D0%BC%D0%B0%D0%B3/?x=1' ), 'https://site.ru › маг' );
} );

test( 'уровень оценки', () => {
	assert.equal( utils.scoreLevel( 0 ), 'bad' );
	assert.equal( utils.scoreLevel( 40 ), 'bad' );
	assert.equal( utils.scoreLevel( 41 ), 'ok' );
	assert.equal( utils.scoreLevel( 70 ), 'ok' );
	assert.equal( utils.scoreLevel( '71' ), 'good' );
	assert.equal( utils.scoreLevel( '' ), 'none' );
	assert.equal( utils.scoreLevel( null ), 'none' );
} );

test( 'слова ключа выделяются с учётом формы слова', () => {
	const parts = utils.keywordParts( 'Лучшие колоды мага сезона', 'колода мага' );
	const bold = parts.filter( ( part ) => part.bold ).map( ( part ) => part.text );

	assert.deepEqual( bold, [ 'колоды', 'мага' ] );
	assert.equal( parts.map( ( part ) => part.text ).join( '' ), 'Лучшие колоды мага сезона' );
	assert.deepEqual( utils.keywordParts( 'Текст', '' ), [ { text: 'Текст', bold: false } ] );
} );

test( 'ключевые слова через запятую: без пустых, повторов и сверх лимита', () => {
	assert.deepEqual( utils.splitKeywords( ' маг, Маг, , берн маг, a, b, c ', 4 ), [ 'маг', 'берн маг', 'a', 'b' ] );
} );

test( 'слова считаются так же, как на сервере', () => {
	assert.equal( utils.countWords( '' ), 0 );
	assert.equal( utils.countWords( '  \n ' ), 0 );
	assert.equal( utils.countWords( 'Блад-ДК — 54 % побед, топ-1.' ), 4 );
	assert.equal( utils.countWords( 'слово '.repeat( 45 ) ), 45 );
} );
