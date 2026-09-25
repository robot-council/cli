<?php
// cli#40: does SecretToolStore collapse keys that differ only in case? Drives the real store class.
require getcwd().'/vendor/autoload.php';
use App\Support\Credentials\Credential;
use App\Support\Credentials\SecretToolStore;
$s = new SecretToolStore;
if (! $s->available()) { echo "UNAVAILABLE\n"; exit(2); }
$n = bin2hex(random_bytes(4));
$upper = "https://FleetA-$n.example|claude";
$lower = strtolower($upper);
$absent = "https://absent-$n.example|claude";
$tag = fn (?Credential $c): string => $c === null ? 'null' : (str_starts_with($c->reveal(), 'UPPER-') ? 'UPPER token' : (str_starts_with($c->reveal(), 'lower-') ? 'lower token' : 'other'));
$fail = 0;
$check = function (string $what, string $got, string $want) use (&$fail) { $ok = $got === $want; $fail += $ok ? 0 : 1; printf("%-58s got %-12s want %-12s %s\n", $what, $got, $want, $ok ? 'ok' : 'DIFFERS'); };
$s->put($upper, new Credential("UPPER-$n"));
$check('negative control: never-stored key', $tag($s->get($absent)), 'null');
$check('positive control: the stored key itself', $tag($s->get($upper)), 'UPPER token');
$check('READ side: lowercased key, never stored', $tag($s->get($lower)), 'null');
$s->put($lower, new Credential("lower-$n"));
$check('WRITE side: upper key after storing lowercased', $tag($s->get($upper)), 'UPPER token');
$check('the lowercased key reads its own', $tag($s->get($lower)), 'lower token');
$s->forget($upper); $s->forget($lower);
$check('cleanup: upper gone', $tag($s->get($upper)), 'null');
$check('cleanup: lower gone', $tag($s->get($lower)), 'null');
echo $fail === 0 ? "RESULT: case-sensitive on both sides\n" : "RESULT: $fail check(s) differ\n";
