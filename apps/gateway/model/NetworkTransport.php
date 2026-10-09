<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;

/** Applies one immutable Gateway network revision to a framework Curl client. */
final class NetworkTransport
{
    /**
     * @param object $client RCMAKER Curl client.
     * @return array{via_proxy:bool,loopback:bool}
     * @throws GatewayError
     */
    public static function configure($client, array $revision, string $url, int $timeoutSeconds, int $connectTimeoutSeconds = 8, bool $follow = false): array
    {
        NetworkSettings::usable($revision);
        $parts = parse_url($url);
        if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new GatewayError('NETWORK_TARGET_INVALID', '出站目标必须是无内嵌凭据的 HTTPS 地址。', 503);
        }
        $loopback = in_array(strtolower(trim((string) $parts['host'], '[]')), ['localhost', '127.0.0.1', '::1'], true);
        $viaProxy = $revision['mode'] === 'manual' && !$loopback;

        $client->setTimeout($timeoutSeconds);
        $client->setOpt(CURLOPT_CONNECTTIMEOUT, $connectTimeoutSeconds);
        $client->setOpt(CURLOPT_SSL_VERIFYPEER, true);
        $client->setOpt(CURLOPT_SSL_VERIFYHOST, 2);
        $client->setOpt(CURLOPT_FOLLOWLOCATION, $follow);
        $client->setOpt(CURLOPT_MAXREDIRS, $follow ? 5 : 0);
        $client->setOpt(CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        $client->setOpt(CURLOPT_VERBOSE, false);
        $client->setOpt(CURLOPT_NOPROXY, '');
        // Explicitly clear environment/system proxy inheritance before applying the selected revision.
        $client->setOpt(CURLOPT_PROXY, '');
        $ca = (string) Config::get('gateway', 'probe_ca_file');
        if ($ca !== '') $client->setOpt(CURLOPT_CAINFO, $ca);

        if ($viaProxy) {
            $proxyHost = str_contains((string) $revision['host'], ':') ? '[' . $revision['host'] . ']' : $revision['host'];
            $client->setProxy('http://' . $proxyHost, (int) $revision['port']);
            $client->setProxyType(CURLPROXY_HTTP);
            $client->setProxyTunnel(true);
            if ($revision['protected_password'] !== null) {
                $secret = ProxyCredential::reveal((string) $revision['protected_password']);
                try {
                    $client->setOpt(CURLOPT_PROXYUSERNAME, (string) $revision['username']);
                    $client->setOpt(CURLOPT_PROXYPASSWORD, $secret);
                    $client->setProxyAuth(CURLAUTH_BASIC);
                } finally {
                    unset($secret);
                }
            }
        }
        return ['via_proxy' => $viaProxy, 'loopback' => $loopback];
    }
}
