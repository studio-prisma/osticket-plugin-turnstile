<?php
require_once __DIR__ . '/failcopy/src/TurnstileVerifier.php';
$p=0;$f=0;
function ok($c,$n,$x=''){ global $p,$f; if($c){$p++;echo "  PASS  $n\n";} else {$f++;echo "  FAIL  $n".($x?"  -> $x":"")."\n";} }
$T='XXXX.DUMMY.TOKEN.XXXX';
$base=['secret'=>'1x0000000000000000000000000000000AA','hostname'=>'','log'=>false,'area'=>'failtest'];

echo "\n== F1 Blackhole-Host, fail_mode=closed ==\n";
TurnstileVerifier::resetCache();
$t=microtime(true); $r=TurnstileVerifier::verify($T,$base+['timeout'=>3,'fail_mode'=>'closed']); $d=microtime(true)-$t;
ok($r['ok']===false,'blockiert',var_export($r['ok'],true));
ok($r['reason']==='unreachable','reason=unreachable',$r['reason']);
ok($d<5.0,sprintf('CURLOPT_TIMEOUT greift (%.2fs bei timeout=3)',$d),sprintf('%.2fs',$d));
ok(strpos($r['message'],'192.0.2.1')===false,'keine interne URL in der Nutzermeldung',$r['message']);
ok(strpos($r['message'],'curl')===false,'kein curl-Detail in der Nutzermeldung',$r['message']);

echo "\n== F2 Blackhole-Host, fail_mode=open ==\n";
TurnstileVerifier::resetCache();
$t=microtime(true); $r2=TurnstileVerifier::verify($T,$base+['timeout'=>3,'fail_mode'=>'open']); $d2=microtime(true)-$t;
ok($r2['ok']===true,'lässt durch');
ok($r2['reason']==='unreachable','reason=unreachable',$r2['reason']);
ok($d2<5.0,sprintf('auch hier zeitlich begrenzt (%.2fs)',$d2));

echo "\n== F3 fail_open wird nicht gecacht ==\n";
$r3=TurnstileVerifier::verify($T,$base+['timeout'=>3,'fail_mode'=>'closed']);
ok($r3['ok']===false,'nach fail-open-Durchlass greift closed wieder (kein vergifteter Cache)',var_export($r3['ok'],true));

echo "\n== F4 Connection refused (schneller Pfad) ==\n";
TurnstileVerifier::resetCache();
echo "\n".str_repeat('-',52)."\n";
printf("Fail-Mode  PASS: %d  FAIL: %d\n",$p,$f);
exit($f>0?1:0);
