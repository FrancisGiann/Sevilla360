<?php
declare(strict_types=1);
require_once __DIR__.'/provider.php';
require_once __DIR__.'/simulator.php';
/** Exercises REAL adapter serialization/parsing with a database-backed FAKE HTTP transport. */
function pm_fixture_provider(PayMongoPrototype $store): PayMongoTestProvider {
    $sim=new PayMongoSimulator($store);
    return new PayMongoTestProvider('sk_test_PUBLIC_OFFLINE_FIXTURE',static function($method,$url,$headers,$body)use($store,$sim){
        $path=parse_url($url,PHP_URL_PATH);
        $store->query('INSERT INTO pm_transport_log (method,path) VALUES (?,?)',[$method,$path]);
        if($method==='POST' && $path==='/v1/checkout_sessions'){
            $input=json_decode($body,true,32,JSON_THROW_ON_ERROR)['data']['attributes'];
            $r=$sim->create(['id'=>$input['reference_number'],'amount_cents'=>$input['line_items'][0]['amount']]);
        }elseif(preg_match('~\A/v1/checkout_sessions/cs_sim_([a-f0-9]{32})(/expire)?\z~D',$path,$m)){
            $r=isset($m[2])?$sim->expire($m[1]):$sim->find($m[1]);
        }else return ['status'=>404,'body'=>'{}'];
        if(!$r)return ['status'=>404,'body'=>'{}'];
        $r['attributes']['checkout_url']='https://checkout.paymongo.com/'.$r['id'];
        return ['status'=>200,'body'=>json_encode(['data'=>$r],JSON_THROW_ON_ERROR)];
    });
}
