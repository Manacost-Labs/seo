<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\CrossSite;

use IndexPilot\CrossSite\PartnerClient;
use PHPUnit\Framework\TestCase;

final class PartnerUrlTest extends TestCase {

	public function test_only_https_address_is_accepted(): void {
		self::assertSame( 'https://kolodahearthstone.com', PartnerClient::secure_url( ' https://kolodahearthstone.com ' ) );
		self::assertSame( '', PartnerClient::secure_url( 'http://kolodahearthstone.com' ) );
		self::assertSame( '', PartnerClient::secure_url( 'kolodahearthstone.com' ) );
		self::assertSame( '', PartnerClient::secure_url( 'https://localhost' ) );
		self::assertSame( '', PartnerClient::secure_url( '' ) );
	}
}
