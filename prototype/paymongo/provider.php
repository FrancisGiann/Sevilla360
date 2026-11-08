<?php
declare(strict_types=1);
final class PayMongoRefundRejected extends RuntimeException {
    public function __construct(public readonly string $providerCode) { parent::__construct('PayMongo rejected the refund request.'); }
}
class PayMongoRefundOutcomeUnknown extends RuntimeException {}
final class PayMongoRefundVerificationFailure extends PayMongoRefundOutcomeUnknown {
    public function __construct(public readonly string $diagnosticCode) {
        if (!preg_match('/\A(?:payment_request_transport|payment_http_[0-9]{3}|payment_response_unreadable|payment_resource_invalid|refund_history_transport|refund_history_http_[0-9]{3}(?:_provider_[a-z0-9_]{1,48})?(?:_field_(?:payment_id|limit|after))?|refund_history_response_unreadable|refund_history_resource_invalid|refund_history_pagination_invalid|refund_history_page_limit)\z/D', $diagnosticCode)) {
            throw new InvalidArgumentException('Invalid refund verification diagnostic.');
        }
        parent::__construct('PayMongo refund details could not be verified.');
    }
}

/** Real v1 Hosted Checkout adapter. Injection allows offline transport contract tests. */
final class PayMongoTestProvider {
    private Closure $transport;
    public function __construct(#[SensitiveParameter] private string $key, ?callable $transport=null, private string $returnBase='http://127.0.0.1:8787/', private array $methods=['card','gcash','paymaya']) {
        if (!preg_match('/\Ask_test_[A-Za-z0-9_]+\z/D',$key)) throw new RuntimeException('A PayMongo test secret key is required; live keys are rejected.');
        self::paymentMethods(implode(',', $this->methods));
        $return=parse_url($this->returnBase);
        if ($this->returnBase!=='http://127.0.0.1:8787/' && (!$return || ($return['scheme']??'')!=='https' || empty($return['host']) || isset($return['user']) || isset($return['pass']) || isset($return['port']) || isset($return['query']) || isset($return['fragment']) || preg_match('/[\x00-\x20\x7f]/',$this->returnBase))) throw new RuntimeException('Invalid configured test return URL.');
        $this->transport=$transport ? Closure::fromCallable($transport) : Closure::fromCallable([$this,'http']);
    }
    public static function fromEnvironment(): self {
        // Never loads an existing .env or searches another checkout for credentials.
        if (getenv('SEVILLA_PM_ALLOW_TEST_HTTP')!=='1') throw new RuntimeException('External PayMongo test requests are disabled. Explicit authorization and process-local configuration are required.');
        $key=(string)getenv('PAYMONGO_SECRET_KEY');
        if ($key==='' && getenv('SEVILLA_PM_USE_EXISTING_ENV')==='1') {
            $key=self::existingConfiguration('/var/www/html/Sevilla360/.env')['PAYMONGO_SECRET_KEY'] ?? '';
        }
        return new self($key,null,'http://127.0.0.1:8787/',self::paymentMethods(getenv('PAYMONGO_TEST_PAYMENT_METHODS')?:'card,gcash,paymaya'));
    }
    /** Checkout Sessions identifiers, not generic Payment Method bank codes. No capability claim. */
    public static function paymentMethods(string $configured): array {
        $methods=array_map('trim',explode(',',$configured));
        $allowed=['card','gcash','paymaya','dob','dob_ubp','brankas_bdo','brankas_landbank','brankas_metrobank'];
        if (!$methods || count($methods)!==count(array_unique($methods)) || array_diff($methods,$allowed)) throw new RuntimeException('Unsupported or duplicate Checkout payment method configuration.');
        return $methods;
    }
    /** Read only allowlisted PayMongo settings. No exports, eval, DB config or persistent writes. */
    public static function existingConfiguration(string $path): array {
        $file=@fopen($path,'r');
        if (!$file) throw new RuntimeException('Existing PayMongo configuration is unavailable.');
        $values=[];
        try {
            while (($line=fgets($file))!==false) {
                if (!preg_match('/^\s*(?:export\s+)?(PAYMONGO_SECRET_KEY|PAYMONGO_WEBHOOK_SECRET)\s*=\s*(.*?)\s*$/D',$line,$m)) continue;
                if (array_key_exists($m[1],$values)) throw new RuntimeException('Duplicate PayMongo configuration name.');
                $value=$m[2];
                if (strlen($value)>=2 && ($value[0]==="'" || $value[0]==='"') && $value[0]===$value[strlen($value)-1]) $value=substr($value,1,-1);
                // Do not evaluate shell expansion or interpolate other variables.
                if (str_contains($value,'$') || str_contains($value,'`') || str_contains($value,"\n")) throw new RuntimeException('Unsupported PayMongo configuration format.');
                $values[$m[1]]=$value;
            }
        } finally { fclose($file); }
        return $values;
    }
    public static function webhookSecret(): string {
        $secret=(string)getenv('PAYMONGO_WEBHOOK_SECRET');
        if ($secret==='' && getenv('SEVILLA_PM_USE_EXISTING_ENV')==='1') {
            $secret=self::existingConfiguration('/var/www/html/Sevilla360/.env')['PAYMONGO_WEBHOOK_SECRET'] ?? '';
        }
        return $secret;
    }
    public static function checkoutUrl(mixed $url): string {
        $p=is_string($url)?parse_url($url):false;
        if (!$p || ($p['scheme']??'')!=='https' || ($p['host']??'')!=='checkout.paymongo.com'
            || isset($p['user']) || isset($p['pass']) || isset($p['port']) || preg_match('/[\x00-\x20\x7f]/',$url)) throw new RuntimeException('Invalid hosted checkout destination.');
        return $url;
    }
    private function call(string $method,string $path,?array $body=null): array {
        try { $response=($this->transport)($method,'https://api.paymongo.com'.$path,
            ['Authorization'=>'Basic '.base64_encode($this->key.':'),'Content-Type'=>'application/json','Accept'=>'application/json'],
            $body===null?null:json_encode($body,JSON_THROW_ON_ERROR)); }
        catch (Throwable $e) { throw new RuntimeException('PayMongo request outcome is uncertain; recover before retrying.'); }
        if (($response['status']??0)!==200) throw new RuntimeException('PayMongo request was not confirmed (HTTP '.(int)($response['status']??0).'). Recover before retrying.');
        try { $decoded=json_decode($response['body'],true,32,JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new RuntimeException('Invalid PayMongo response; recover before retrying.'); }
        $r=$decoded['data']??null;
        if (!is_array($r) || ($r['type']??'')!=='checkout_session' || ($r['attributes']['livemode']??null)!==false
            || !preg_match('/\Acs_[A-Za-z0-9_]+\z/D',$r['id']??'')) throw new RuntimeException('PayMongo returned an invalid or non-test checkout.');
        // Do not return billing, client keys or unrelated provider data to callers.
        $a=$r['attributes'];
        $clean=['id'=>$r['id'],'type'=>'checkout_session','attributes'=>array_intersect_key($a,array_flip(['livemode','reference_number','status','line_items','checkout_url']))];
        if (array_key_exists('payments',$a)) {
            if (!is_array($a['payments'])) throw new RuntimeException('Malformed payment collection.');
            $clean['attributes']['payments']=array_map(static function($p){
                if (!is_array($p) || !is_array($p['attributes']??null)) throw new RuntimeException('Malformed payment resource.');
                $attributes=array_intersect_key($p['attributes'],array_flip(['livemode','status','amount','currency','fee','net_amount','source']));
                if (isset($attributes['source']) && is_array($attributes['source'])) {
                    $attributes['source']=array_intersect_key($attributes['source'],array_flip(['type']));
                }
                return ['id'=>$p['id']??null,'type'=>$p['type']??null,'attributes'=>$attributes];
            },$a['payments']);
        }
        if (isset($clean['attributes']['checkout_url'])) self::checkoutUrl($clean['attributes']['checkout_url']);
        return $clean;
    }
    public function create(array $attempt): array {
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$attempt['id']??'')) throw new RuntimeException('Invalid attempt reference.');
        $cents=filter_var($attempt['amount_cents']??null,FILTER_VALIDATE_INT);
        if ($cents===false || $cents<100 || $cents>999999999) throw new RuntimeException('Invalid hosted checkout amount.');
        $return=$this->returnBase.'?attempt='.$attempt['id'];
        return $this->call('POST','/v1/checkout_sessions',['data'=>['attributes'=>[
            'line_items'=>[['name'=>'Sevilla360 booking payment','amount'=>$cents,'currency'=>'PHP','quantity'=>1]],
            'payment_method_types'=>$this->methods,'reference_number'=>$attempt['id'],'success_url'=>$return.'&result=return',
            'cancel_url'=>$return.'&result=cancel','send_email_receipt'=>false,'show_line_items'=>true,
        ]]]);
    }
    public function retrieve(string $id): array { return $this->call('GET',$this->path($id)); }
    public function expire(string $id): array { return $this->call('POST',$this->path($id).'/expire'); }
    private function path(string $id): string {
        if (!preg_match('/\Acs_[A-Za-z0-9_]+\z/D',$id)) throw new RuntimeException('Invalid provider session ID.');
        return '/v1/checkout_sessions/'.$id;
    }
    private function rawRequest(string $method,string $path,?array $body=null): array {
        try {
            return ($this->transport)($method,'https://api.paymongo.com'.$path,
                ['Authorization'=>'Basic '.base64_encode($this->key.':'),'Content-Type'=>'application/json','Accept'=>'application/json'],
                $body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            throw new PayMongoRefundOutcomeUnknown('PayMongo request outcome is uncertain; reconcile before another create.');
        }
    }
    private function decodedData(array $response,string $type): array {
        try { $decoded=json_decode((string)($response['body']??''),true,32,JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new PayMongoRefundOutcomeUnknown('PayMongo returned an unreadable response; reconcile before another create.'); }
        $resource=$decoded['data']??null;
        if (!is_array($resource) || ($resource['type']??null)!==$type || !is_array($resource['attributes']??null)) {
            throw new PayMongoRefundOutcomeUnknown('PayMongo returned an incomplete resource; reconcile before another create.');
        }
        return $resource;
    }
    private function refundRequest(string $method,string $path,?array $body=null): array {
        $response=$this->rawRequest($method,$path,$body);
        $status=(int)($response['status']??0);
        if ($status>=400 && $status<500) {
            $providerCode='provider_rejected'; $field=null;
            try {
                $error=json_decode((string)($response['body']??''),true,16,JSON_THROW_ON_ERROR)['errors'][0]??null;
                if (!is_array($error)) $error=[];
                $knownCodes=[
                    'payment_not_found','refund_amount_exceeds_payment','payment_not_refundable',
                    'insufficient_balance','insufficient_payout_balance','insufficient_available_balance',
                    'payout_balance_insufficient','balance_insufficient','insufficient_funds',
                    'provider_processing_error','resource_processing_state','resource_processing_error',
                    'refund_not_allowed','parameter_invalid','resource_not_found','livemode_mismatched',
                    'resource_failed_state','resource_disputed_state','resource_pending_state',
                    'allowed_date_exceeded','available_balance_insufficient','parameter_above_maximum',
                    'payment_method_not_allowed','same_day_partial_refund_not_allowed',
                ];
                $candidate=$error['code']??null;
                if (is_string($candidate) && in_array(strtolower($candidate),$knownCodes,true)) $providerCode=strtolower($candidate);
                if (in_array($providerCode,['parameter_above_maximum','same_day_partial_refund_not_allowed'],true)) {
                    $providerCode=$this->refundAmountRestrictionClassification($providerCode,$error);
                }
                $field=$this->refundRequestErrorField($error['source']??null);
            } catch (Throwable $ignored) {}
            throw new PayMongoRefundRejected('http_'.$status.'_'.$providerCode.($field===null?'':'_field_'.$field));
        }
        if (!in_array($status,[200,201],true)) throw new PayMongoRefundOutcomeUnknown('PayMongo request outcome is uncertain; reconcile before another create.');
        return $this->decodedData($response,'refund');
    }
    /** Classify only exact allowlisted restriction phrases; never retain provider text. */
    private function refundAmountRestrictionClassification(string $providerCode,array $error): string {
        $sameDayPhrases=[
            'same day partial refund is not allowed',
            'same day partial refunds are not allowed',
            'partial refunds are not allowed on the same day',
            'only a full refund is allowed on the same day',
            'only full refunds are allowed on the same day',
            'partial refunds can only be filed starting 12 00 am the following day',
            'partial refunds are only allowed starting 12 00 am the following day',
        ];
        $fullRefundOnlyPhrases=[
            'only full amounts are allowed to be refunded for the payment',
            'only a full amount is allowed to be refunded for the payment',
            'only full amounts are allowed to be refunded for this payment',
            'only a full amount is allowed to be refunded for this payment',
        ];
        foreach (['detail','message'] as $field) {
            $text=$error[$field]??null;
            if (!is_string($text) || strlen($text)>512) continue;
            $normalized=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i',' ',$text)));
            if (in_array($normalized,$sameDayPhrases,true)) return 'maya_same_day_partial_refund_window';
            if (in_array($normalized,$fullRefundOnlyPhrases,true)) return 'provider_full_refund_only';
        }
        if ($providerCode==='same_day_partial_refund_not_allowed') return 'parameter_above_maximum';
        // Preserve only an allowlisted code. Provider text is never included in
        // the exception or persisted to the refund attempt.
        return $providerCode;
    }
    /** Return only fields used by the Refund request; never retain a raw provider pointer. */
    private function refundRequestErrorField(mixed $source): ?string {
        if (!is_array($source)) return null;
        $fields=[
            'payment_id'=>['payment_id','data.attributes.payment_id','data/attributes/payment_id','/data/attributes/payment_id','data[attributes][payment_id]'],
            'amount'=>['amount','data.attributes.amount','data/attributes/amount','/data/attributes/amount','data[attributes][amount]'],
            'reason'=>['reason','data.attributes.reason','data/attributes/reason','/data/attributes/reason','data[attributes][reason]'],
            'notes'=>['notes','data.attributes.notes','data/attributes/notes','/data/attributes/notes','data[attributes][notes]'],
        ];
        foreach (['pointer','parameter'] as $sourceKey) {
            $value=$source[$sourceKey]??null;
            if (!is_string($value) || strlen($value)>64) continue;
            foreach ($fields as $field=>$aliases) if (in_array($value,$aliases,true)) return $field;
        }
        return null;
    }
    private function validateRefund(array $resource,?string $paymentId=null,?int $amount=null,?string $notes=null): array {
        $a=$resource['attributes'];
        if (!preg_match('/\Aref_[A-Za-z0-9_]+\z/D',$resource['id']??'')
            || ($a['livemode']??null)!==false
            || !preg_match('/\Apay_[A-Za-z0-9_]+\z/D',$a['payment_id']??'')
            || !is_int($a['amount']??null) || $a['amount']<100
            || ($a['currency']??null)!=='PHP'
            || !in_array($a['status']??null,['pending','processing','succeeded','failed'],true)
            || ($paymentId!==null && $a['payment_id']!==$paymentId)
            || ($amount!==null && $a['amount']!==$amount)
            || ($notes!==null && ($a['notes']??null)!==$notes)) {
            throw new PayMongoRefundOutcomeUnknown('PayMongo returned an unbound refund resource; reconcile manually.');
        }
        return ['id'=>$resource['id'],'type'=>'refund','attributes'=>array_intersect_key($a,array_flip(['amount','currency','livemode','notes','payment_id','reason','status','created_at','updated_at']))];
    }
    /** Retrieve an original Payment so an absent fee stays unknown until verified. */
    public function retrievePayment(string $id): array {
        if (!preg_match('/\Apay_[A-Za-z0-9_]+\z/D',$id)) throw new RuntimeException('Invalid PayMongo payment ID.');
        try { $response=$this->rawRequest('GET','/v1/payments/'.$id); }
        catch (PayMongoRefundOutcomeUnknown $e) { throw new PayMongoRefundVerificationFailure('payment_request_transport'); }
        $status=(int)($response['status']??0);
        if ($status!==200) throw new PayMongoRefundVerificationFailure('payment_http_'.str_pad((string)$status,3,'0',STR_PAD_LEFT));
        try { $resource=$this->decodedData($response,'payment'); }
        catch (PayMongoRefundOutcomeUnknown $e) { throw new PayMongoRefundVerificationFailure('payment_response_unreadable'); }
        $a=$resource['attributes'];
        if ($resource['id']!==$id || ($a['livemode']??null)!==false || ($a['status']??null)!=='paid'
            || ($a['currency']??null)!=='PHP' || !is_int($a['amount']??null) || $a['amount']<1
            || !is_int($a['fee']??null) || $a['fee']<0 || $a['fee']>$a['amount']) {
            throw new PayMongoRefundVerificationFailure('payment_resource_invalid');
        }
        $net=$a['net_amount']??null;
        return ['id'=>$id,'type'=>'payment','attributes'=>[
            'amount'=>$a['amount'],'currency'=>'PHP','fee'=>$a['fee'],
            'net_amount'=>is_int($net)&&$net>=0?$net:null,'livemode'=>false,'status'=>'paid'
        ]];
    }
    /** Create once with a stable note; uncertain results must be reconciled, never blindly retried. */
    public function createRefund(string $paymentId,int $amount,string $notes): array {
        if (!preg_match('/\Apay_[A-Za-z0-9_]+\z/D',$paymentId) || $amount<100 || $amount>999999999999
            || $notes==='' || strlen($notes)>255 || preg_match('/[\x00-\x1F\x7F]/',$notes)) throw new RuntimeException('Invalid PayMongo refund request.');
        $resource=$this->refundRequest('POST','/v1/refunds',['data'=>['attributes'=>[
            'payment_id'=>$paymentId,'amount'=>$amount,'reason'=>'others','notes'=>$notes,
        ]]]);
        return $this->validateRefund($resource,$paymentId,$amount,$notes);
    }
    public function retrieveRefund(string $id): array {
        if (!preg_match('/\Aref_[A-Za-z0-9_]+\z/D',$id)) throw new RuntimeException('Invalid PayMongo refund ID.');
        $resource=$this->refundRequest('GET','/v1/refunds/'.$id);
        return $this->validateRefund($resource);
    }
    /** List every refund for one payment; the API's payment filter is authoritative. */
    public function refundsForPayment(string $paymentId): array {
        if (!preg_match('/\Apay_[A-Za-z0-9_]+\z/D',$paymentId)) throw new RuntimeException('Invalid PayMongo payment ID.');
        $all=[]; $after=null;
        for ($page=0; $page<100; $page++) {
            $query=['payment_id'=>$paymentId,'limit'=>'100'];
            if ($after!==null) $query['after']=$after;
            try { $response=$this->rawRequest('GET','/refunds?'.http_build_query($query,'','&',PHP_QUERY_RFC3986)); }
            catch (PayMongoRefundOutcomeUnknown $e) { throw new PayMongoRefundVerificationFailure('refund_history_transport'); }
            $status=(int)($response['status']??0);
            if ($status!==200) throw new PayMongoRefundVerificationFailure($this->refundHistoryHttpDiagnostic($status,$response));
            try { $decoded=json_decode((string)($response['body']??''),true,64,JSON_THROW_ON_ERROR); }
            catch (Throwable $e) { throw new PayMongoRefundVerificationFailure('refund_history_response_unreadable'); }
            $items=$decoded['data']??null;
            if (!is_array($items)) throw new PayMongoRefundVerificationFailure('refund_history_response_unreadable');
            foreach ($items as $item) {
                if (!is_array($item)) throw new PayMongoRefundVerificationFailure('refund_history_resource_invalid');
                try { $all[]=$this->validateRefund($item,$paymentId); }
                catch (PayMongoRefundOutcomeUnknown $e) { throw new PayMongoRefundVerificationFailure('refund_history_resource_invalid'); }
            }
            $hasMore=$decoded['has_more']??(count($items)===100);
            if ($hasMore!==true) return $all;
            $last=$items ? end($items) : null;
            $cursor=is_array($last)?($last['id']??null):null;
            if (!is_string($cursor) || $cursor===$after || !preg_match('/\Aref_[A-Za-z0-9_]+\z/D',$cursor)) throw new PayMongoRefundVerificationFailure('refund_history_pagination_invalid');
            $after=$cursor;
        }
        throw new PayMongoRefundVerificationFailure('refund_history_page_limit');
    }
    private function refundHistoryHttpDiagnostic(int $status,array $response): string {
        $category='refund_history_http_'.str_pad((string)$status,3,'0',STR_PAD_LEFT);
        try { $error=json_decode((string)($response['body']??''),true,16,JSON_THROW_ON_ERROR)['errors'][0]??null; }
        catch (Throwable $ignored) { return $category; }
        if (!is_array($error)) return $category;
        $providerCode=$error['code']??null;
        if (is_string($providerCode) && strlen($providerCode)<=48 && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,47}\z/D',$providerCode)
            && !preg_match('/\A(?:pay|ref|pi|pii|cs|pm|cus|src|ch|po|bal|txn|org|acct|sk|pk|whsec)_/i',$providerCode)) {
            $category.='_provider_'.str_replace('-','_',strtolower($providerCode));
        }
        $source=$error['source']??null;
        if (is_array($source)) {
            $fields=[
                'payment_id'=>['payment_id','data.attributes.payment_id','data/attributes/payment_id','/data/attributes/payment_id','data[attributes][payment_id]'],
                'limit'=>['limit','data.attributes.limit','data/attributes/limit','/data/attributes/limit','data[attributes][limit]'],
                'after'=>['after','data.attributes.after','data/attributes/after','/data/attributes/after','data[attributes][after]'],
            ];
            foreach (['pointer','parameter'] as $sourceKey) {
                $sourceValue=$source[$sourceKey]??null;
                if (!is_string($sourceValue) || strlen($sourceValue)>64) continue;
                foreach ($fields as $field=>$aliases) {
                    if (in_array($sourceValue,$aliases,true)) { $category.='_field_'.$field; break 2; }
                }
            }
        }
        return $category;
    }
    private function http(string $method,string $url,#[SensitiveParameter] array $headers,?string $body): array {
        $nativeTest = defined('SEVILLA_PM_FULL_APP') && SEVILLA_PM_FULL_APP === true
            && ($_ENV['PAYMONGO_CAPSTONE_MODE'] ?? getenv('PAYMONGO_CAPSTONE_MODE')) === 'test';
        if (!$nativeTest && getenv('SEVILLA_PM_ALLOW_TEST_HTTP')!=='1') throw new RuntimeException('External test HTTP is disabled.');
        $c=curl_init($url); $buffer='';
        curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_map(static fn($k,$v)=>$k.': '.$v,array_keys($headers),$headers),
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',
            CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$buffer){ if(strlen($buffer)+strlen($chunk)>1048576)return 0;$buffer.=$chunk;return strlen($chunk); }]);
        if ($body!==null) curl_setopt($c,CURLOPT_POSTFIELDS,$body);
        $ok=curl_exec($c); $status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c);
        if ($ok===false) throw new RuntimeException('Transport outcome uncertain.');
        return ['status'=>$status,'body'=>$buffer];
    }
}
