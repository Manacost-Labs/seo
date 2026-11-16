<?php
declare(strict_types=1);
namespace IndexPilot\Tests\SearchData;
use PHPUnit\Framework\TestCase;
use IndexPilot\SearchData\{Http,GoogleClient,YandexClient,SearchDataException,UrlMatcher,QueryAdvisor};
final class ClientsTest extends TestCase {
 private function transport(callable $callback): Http {
  return new class($callback) implements Http {
   public function __construct(private $callback) {}
   public function request(string $method,string $url,array $headers,string $body): array {return ($this->callback)($method,$url,$headers,$body);}
  };
 }
 public function test_google_signature_cache_rows_and_denial(): void {
  $key=openssl_pkey_new(array('config'=>__DIR__.'/openssl.cnf','private_key_bits'=>2048));
  openssl_pkey_export($key,$pem,null,array('config'=>__DIR__.'/openssl.cnf'));
  $public=openssl_pkey_get_details($key)['key'];
  $calls=0;
  $http=$this->transport(function($method,$url,$headers,$body) use(&$calls,$public){
   ++$calls;
   if(str_contains($url,'oauth2')){
    parse_str($body,$form);
    $parts=explode('.',$form['assertion']);
    self::assertCount(3,$parts);
    $decode=static fn($s)=>base64_decode(strtr($s,'-_','+/'));
    self::assertSame(1,openssl_verify($parts[0].'.'.$parts[1],$decode($parts[2]),$public,OPENSSL_ALGO_SHA256));
    $claims=json_decode($decode($parts[1]),true);
    self::assertSame(3600,$claims['exp']-$claims['iat']);
    return array('code'=>200,'body'=>'{"access_token":"abc","expires_in":3600}');
   }
   return array('code'=>200,'body'=>'{"rows":[{"keys":["/a","test"],"clicks":3,"impressions":200,"position":4}]}');
  });
  $json=json_encode(array('type'=>'service_account','client_email'=>'test@example.org','private_key'=>$pem));
  $client=new GoogleClient($json,$http,static fn()=>1000);
  self::assertSame('abc',$client->token());
  self::assertSame('abc',$client->token());
  self::assertSame(1,$calls);
  self::assertSame(array(array('page'=>'/a','query'=>'test','clicks'=>3,'impressions'=>200,'position'=>4.0)),$client->query('https://example.org','2026-01-01','2026-01-28'));
  $denied=new GoogleClient($json,$this->transport(static fn()=>array('code'=>403,'body'=>'')),static fn()=>1000);
  try {$denied->token();self::fail('Expected denial');} catch(SearchDataException $e) {
   self::assertStringContainsString('добавьте её адрес test@example.org',$e->getMessage());
   self::assertStringNotContainsString($pem,$e->getMessage());
  }
 }
 public function test_yandex_host_and_rows(): void {
  $client=new YandexClient('secret',$this->transport(static function($method,$url,$headers){
   self::assertSame('OAuth secret',$headers['Authorization']);
   $data=str_ends_with($url,'/user')?array('user_id'=>7):(str_ends_with($url,'/hosts')?array('hosts'=>array(array('host_id'=>'host','unicode_host_url'=>'http://www.example.org/'))):array('queries'=>array(array('query_text'=>'test','indicators'=>array('TOTAL_SHOWS'=>20,'TOTAL_CLICKS'=>2,'AVG_SHOW_POSITION'=>3)))));
   return array('code'=>200,'body'=>json_encode($data));
  }));
  self::assertSame('host',$client->host_id('https://example.org/'));
  self::assertSame(array(array('page'=>'','query'=>'test','clicks'=>2,'impressions'=>20,'position'=>3.0)),$client->popular_queries('host','2026-01-01','2026-01-28'));
 }
 public function test_matcher_and_advisor(): void {
  $matcher=new UrlMatcher(static fn()=>0,static fn($url)=>'https://example.org/category/gajdy'===$url?42:0,static fn()=>0);
  self::assertSame(array('type'=>'term','id'=>42),$matcher->match('https://example.org/category/gajdy/page/2/?a=1#x'));
  $advisor=new QueryAdvisor();
  $rows=array(array('query'=>'гайд','impressions'=>100,'clicks'=>10,'position'=>6.0));
  $same=static fn($a,$b)=>str_contains($b,$a);
  self::assertSame('missing_in_title',$advisor->hints($rows,'Обзор','','гайд',$same)[0]['type']);
  self::assertSame(array(),$advisor->hints($rows,'Полный гайд','','гайд',$same));
 }
}