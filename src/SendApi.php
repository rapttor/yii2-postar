<?php

declare(strict_types=1);

namespace rapttor\postar;

/**
 * The live Postar API (https://postar.vip/llm.txt): `POST /v1/send` and `/v1/unsubscribes`, `Bearer pk_...`, one recipient per call.
 * Plain curl, no SDK needed. 202 = queued; 422 also covers a suppressed recipient (see {@see PostarApiException::isSuppressed()}).
 */
final class SendApi
{
    /** @var (callable(string $url, array<string,string> $headers, string $body, string $method): array{0:int,1:string})|null for tests */
    private $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.postar.vip',
        private readonly int $timeout = 10,
        ?callable $http = null,
    ) {
        $this->http = $http;
    }

    /**
     * @param array<string,mixed> $payload from, to, subject, html/text, name, reply_to, unsubscribe
     * @return array{id:string, status:string}
     * @throws PostarApiException
     */
    public function send(array $payload): array
    {
        [$status, $data] = $this->request('POST', '/v1/send', $payload);
        if ($status !== 202) {
            throw $this->error($status, $data);
        }

        return ['id' => (string) ($data['id'] ?? ''), 'status' => (string) ($data['status'] ?? 'queued')];
    }

    /**
     * Records an opt-out that happened inside the app.
     *
     * @return bool true = newly blocked (201), false = was already blocked (200)
     * @throws PostarApiException
     */
    public function unsubscribe(string $email): bool
    {
        [$status, $data] = $this->request('POST', '/v1/unsubscribes', ['email' => $email]);
        if ($status !== 201 && $status !== 200) {
            throw $this->error($status, $data);
        }

        return $status === 201;
    }

    /**
     * @return array{email:string, unsubscribed:bool, source:?string, since:?string}
     * @throws PostarApiException
     */
    public function unsubscribeStatus(string $email): array
    {
        [$status, $data] = $this->request('GET', '/v1/unsubscribes?email=' . rawurlencode($email));
        if ($status !== 200) {
            throw $this->error($status, $data);
        }

        return [
            'email' => (string) ($data['email'] ?? $email),
            'unsubscribed' => (bool) ($data['unsubscribed'] ?? false),
            'source' => isset($data['source']) ? (string) $data['source'] : null,
            'since' => isset($data['since']) ? (string) $data['since'] : null,
        ];
    }

    /**
     * Lifts a block the app placed. A block the recipient made with the email link answers 409:
     * the exception carries {@see PostarApiException::resubscribeUrl()} to send the user to.
     *
     * @throws PostarApiException
     */
    public function resubscribe(string $email): void
    {
        [$status, $data] = $this->request('DELETE', '/v1/unsubscribes?email=' . rawurlencode($email));
        if ($status < 200 || $status > 204) {
            throw $this->error($status, $data);
        }
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey, 'Content-Type' => 'application/json'];
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        [$status, $raw] = $this->http !== null ? ($this->http)($url, $headers, $body, $method) : $this->curl($url, $headers, $body, $method);
        $data = json_decode($raw, true);

        return [$status, is_array($data) ? $data : []];
    }

    /** @param array<string,mixed> $data */
    private function error(int $status, array $data): PostarApiException
    {
        return new PostarApiException(isset($data['error']) ? (string) $data['error'] : "Postar error (HTTP $status)", $status, $data);
    }

    /** @param array<string,string> $headers @return array{0:int,1:string} */
    private function curl(string $url, array $headers, string $body, string $method): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout, CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($raw === false) {
            throw new PostarApiException("Postar request failed: $err", 0);
        }

        return [$status, (string) $raw];
    }
}
