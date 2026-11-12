<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Search;

// sends one JSON request to Ollama or Qdrant and returns the decoded JSON answer (M14)
// both services only ever run on our own machine or private network; nothing here talks to the internet
class JsonHttp {
    // $method is GET, POST, PUT or DELETE; $body is null for no body; $headers are extra lines such as "api-key: ..."
    // throws SearchUnavailable when the service cannot be reached, is too slow, or answers with an error
    public static function request(string $method, string $url, ?array $body, int $timeoutSeconds, array $headers = []): array {
        $client = curl_init($url);
        $headerLines = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
        curl_setopt_array($client, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,   // give the answer back as text instead of printing it
            CURLOPT_CONNECTTIMEOUT => 2,      // a service that is not running fails fast
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_PROXY => '',              // local services are never reached through a proxy
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            // JSON_THROW_ON_ERROR stops at text that cannot be written as JSON instead of sending "false"
            curl_setopt($client, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        $answer = curl_exec($client);
        $status = (int) curl_getinfo($client, CURLINFO_HTTP_CODE);
        if ($answer === false) {
            throw new SearchUnavailable('The search service could not be reached.');
        }
        if ($status < 200 || $status >= 300) {
            throw new SearchUnavailable('The search service answered with status ' . $status . '.');
        }
        $decoded = json_decode((string) $answer, true);
        if (!is_array($decoded)) {
            throw new SearchUnavailable('The search service answered with something that is not JSON.');
        }
        return $decoded;
    }
}
