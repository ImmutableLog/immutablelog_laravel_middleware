# ImmutableLog Laravel Middleware

Middleware global para Laravel (10 ou mais novo; testado no 13) que captura as requisições HTTP que passam por ele e envia um evento de auditoria imutável para o ImmutableLog — todas, exceto `OPTIONS` (preflight) e os paths de `skip_paths` (comparação exata; padrão `/health`, `/healthz` e `/up`). Exceção não tratada também gera evento, com status 500 e `error_*`.

O `handle()` só marca o início; o evento é montado e enviado no `terminate()`, que o Laravel chama **depois de entregar a resposta ao cliente**. Uma falha de envio só gera um `Log::warning`.

> **Leia [Envio, PHP-FPM e o tempo de resposta](#envio-php-fpm-e-o-tempo-de-resposta)** antes de usar em produção: em PHP o envio não roda em outra thread, e o modo de servir a aplicação muda o efeito.

---

## Instalação

Nenhum pacote Composer. O arquivo usa só `ext-curl` e `ext-json` (presentes nas imagens oficiais do PHP) e o próprio Laravel. PHP 8.1+.

---

## Adicionar ao projeto

Copie `ImmutableLogMiddleware.php` para:

```
app/Http/Middleware/ImmutableLogMiddleware.php
```

O arquivo declara `namespace App\Http\Middleware;` e a classe `ImmutableLogMiddleware`.

---

## Variáveis de ambiente

| Variável             | Obrigatório | Default                          | Descrição                              |
|----------------------|-------------|----------------------------------|----------------------------------------|
| `IMTBL_API_KEY`      | Sim         | —                                | Chave de API do ImmutableLog (scope `events:write`) |
| `IMTBL_URL`          | Não         | `https://api.immutablelog.com`   | URL base da API                        |
| `IMTBL_SERVICE_NAME` | Não         | `laravel-service`                | Nome do serviço (`meta.service`)       |
| `IMTBL_ENV`          | Não         | `production`                     | Ambiente (`meta.environment` e `meta.env`) |

O middleware lê primeiro `config('services.immutablelog.*')` e só depois `env()`. **Use o `config/services.php`**: com `php artisan config:cache` o `.env` não é mais carregado em tempo de execução, e só a config sobrevive.

```php
// config/services.php
'immutablelog' => [
    'key'     => env('IMTBL_API_KEY'),
    'url'     => env('IMTBL_URL', 'https://api.immutablelog.com'),
    'service' => env('IMTBL_SERVICE_NAME', 'laravel-service'),
    'env'     => env('IMTBL_ENV', 'production'),
    'timeout' => 2,                       // segundos; ver a seção sobre PHP-FPM
    // 'skip_paths' => ['/health', '/up'],
    // 'trust_trail_header' => false,
],
```

```dotenv
# .env
IMTBL_API_KEY=iml_live_xxxxxxxxxxxxxxxx
IMTBL_SERVICE_NAME=meu-servico-laravel
IMTBL_ENV=production
```

Sem chave, o middleware **não envia nada**.

> **Segurança:** nunca coloque a chave no código nem no repositório. `.env` fora do git, ou secrets manager.
>
> **Ambiente do processo:** `php artisan serve` só repassa ao servidor embutido as variáveis do `.env` e uma lista fixa (`APP_ENV`, `PATH`…); uma `IMTBL_API_KEY` exportada só no shell não chega ao PHP. O PHP-FPM, por padrão (`clear_env = yes`), também descarta o ambiente do processo. Nos dois casos, use o `.env` ou o `config/services.php`.

---

## Registro na aplicação

Laravel 11 ou mais novo — `bootstrap/app.php`:

```php
use App\Http\Middleware\ImmutableLogMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    // ...
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(ImmutableLogMiddleware::class);   // global
    })
    ->create();
```

Laravel 10 — `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \App\Http\Middleware\ImmutableLogMiddleware::class,
];
```

Precisa ser **global**: o `terminate()` só roda para middleware registrado no kernel.

---

## Como funciona

| Etapa          | Descrição                                                                    |
|----------------|------------------------------------------------------------------------------|
| `handle()`     | Marca `imtbl.started_at` nos atributos da request (exceto `OPTIONS` e `skip_paths`) |
| resposta       | O Laravel envia a resposta ao cliente                                        |
| `terminate()`  | Lê status, rota, exceção e marcadores; monta o evento; POST via cURL         |
| `Idempotency-Key` | Um UUID novo por evento (`Str::uuid()`), igual a `meta.request_id`        |

O middleware nunca lê o body da requisição: nem conteúdo, nem hash.

---

## Nome de evento e trilha customizados

Em qualquer controller ou closure de rota:

```php
public function store(Request $request)
{
    $request->attributes->set('imtbl.event_name', 'payment.created');
    $request->attributes->set('imtbl.trail', 'order-7782');

    return response()->json(['ok' => true], 201);
}
```

- Sem nome definido, o evento é `http.{MÉTODO}.{path}` (ex.: `http.GET./users/42`).
- O core recusa trilha vazia, com mais de 256 caracteres ou com `:` (`400 invalid_immutable_trail`); por isso o middleware troca `:` por `-`, corta em 256 e omite a chave se sobrar vazio.
- **Header `X-Imtbl-Trail`:** só é lido com `'trust_trail_header' => true` em `config/services.php`, e só quando o controller não definiu a trilha. Fica desligado porque o header vem de quem chama o serviço: qualquer chamador poderia pendurar eventos numa trilha alheia do mesmo tenant.

---

## Chaves enviadas em `meta`

Todos os valores são convertidos com `(string)`: o core só aceita `meta` como mapa string → string, e `http_status` vai como `"200"`, nunca como o inteiro `200`.

| Chave             | Valor                                                                 |
|-------------------|-----------------------------------------------------------------------|
| `type`            | `success` (2xx), `info` (3xx e <200), `error` (4xx/5xx)               |
| `event_type`      | mesmo valor de `type` (severidade, não nome — ver `GUIA_EVENTOS_CLIENTE.md`) |
| `event_name`      | atributo `imtbl.event_name` ou `http.{MÉTODO}.{path}`                 |
| `service`         | `services.immutablelog.service` / `IMTBL_SERVICE_NAME`                |
| `environment`     | `services.immutablelog.env` / `IMTBL_ENV` (nome canônico, `CONTRACT.md` §4.1) |
| `env`             | mesmo valor de `environment` (nome legado, mantido como nos outros middlewares) |
| `request_id`      | UUID do evento — o mesmo valor do header `Idempotency-Key`            |
| `http_method`     | `GET`, `POST`…                                                        |
| `http_status`     | status da resposta, em texto                                          |
| `http_route`      | `'/' . $request->route()->uri()` (`/users/{id}`); em 404, o path concreto |
| `log_level`       | `error` para `type: error`, senão `info`                              |
| `client_ip`       | IP do cliente, **sem porta**; a chave é omitida se não houver IP válido |
| `error_type`      | classe da exceção que o Laravel anexou à resposta (`$response->exception`) |
| `error_message`   | mensagem da exceção, até 500 caracteres                               |
| `immutable_trail` | trilha, só quando definida                                            |

Em 404 o Laravel também anexa a exceção, então o evento leva `error_type: Symfony\Component\HttpKernel\Exception\NotFoundHttpException`.

---

## Payload enviado

`payload` é uma string JSON (limite do core: 16 KB; os campos variáveis são cortados — path em 2048, User-Agent em 512):

```json
{
  "id": "uuid",
  "kind": "success",
  "message": "GET /users/42 -> 200",
  "timestamp": "2026-11-04T12:13:16Z",
  "context": { "ip": "203.0.113.7", "user_agent": "curl/8.4.0" },
  "request": { "request_id": "uuid", "method": "GET", "path": "/users/42" },
  "metrics": { "latency_ms": 3, "status_code": 200 },
  "severity": "low",
  "error": { "status_code": 500, "retryable": true, "exception": "...", "exception_message": "..." }
}
```

---

## Envio, PHP-FPM e o tempo de resposta

PHP não tem thread em segundo plano: o POST roda **no mesmo processo**, no `terminate()`.

| Como a aplicação é servida      | Efeito do envio                                                        |
|---------------------------------|------------------------------------------------------------------------|
| **PHP-FPM** (nginx/Apache + FPM)| O Laravel chama `fastcgi_finish_request()` antes do `terminate()`: o cliente recebe a resposta sem esperar o envio. **Mas o worker do FPM fica ocupado até o envio terminar** (no máximo `timeout`). Com o ImmutableLog lento, os workers ficam presos e as requisições seguintes **esperam na fila** do pool. |
| `php artisan serve` / `php -S`  | Não há `fastcgi_finish_request()`: a conexão só fecha depois do `terminate()`. Com o ImmutableLog lento, **cada resposta atrasa até o `timeout`**. Use só em desenvolvimento. |

Medido com o ImmutableLog aceitando a conexão e nunca respondendo, pool padrão do FPM (5 workers): timeout de 5 s → requisições seguintes entre 0,9 s e 2 s; timeout de 1 s → até 0,8 s. Com o ImmutableLog simplesmente fora do ar (conexão recusada), nenhum efeito visível. Por isso: **mantenha o `timeout` baixo** (`services.immutablelog.timeout`, em segundos; padrão 5, o de todos os middlewares) e dimensione o pool. Para isolar de vez, mova o envio para uma fila (`dispatch()` de um job) — não está neste arquivo.

---

## Comportamento

| Situação                          | Resultado                                                         |
|-----------------------------------|-------------------------------------------------------------------|
| Chave vazia                       | Nenhum evento enviado                                             |
| `OPTIONS` ou path em `skip_paths` | Sem evento; a requisição segue normalmente                        |
| Exceção não tratada               | Evento com status 500 e `error_*`; a resposta é a do handler de exceções do Laravel |
| Rota sem match (404)              | Evento com `http_route` igual ao path concreto                    |
| ImmutableLog fora do ar           | Resposta igual; `Log::warning('[ImmutableLog] failed to send event: ...')` |
| ImmutableLog lento                | Ver a seção acima; conexão em 1 s, total em `timeout`; sem retry  |

---

## Proxy e X-Forwarded-For

`client_ip` vem, nesta ordem, do **primeiro item** de `X-Forwarded-For`, de `X-Real-IP` e de `REMOTE_ADDR`. Os headers são lidos **direto**, sem passar pelo `TrustProxies` — a mesma regra dos outros middlewares ImmutableLog, e não o `$request->ip()`.

- Atrás de um proxy/ALB que sobrescreve `X-Forwarded-For`, o valor é o IP do cliente, sem configurar nada.
- **Sem proxy na frente, quem chama o serviço forja o `client_ip`** só mandando o header, e o SIEM promove esse valor a `source.ip`. Regras de detecção por IP passam a confiar num dado que o chamador controla. Se o serviço fica exposto direto, garanta na borda (nginx: `proxy_set_header X-Forwarded-For $remote_addr;`) que o header não chega do cliente.
- Só `client_ip` vira `source.ip` no SIEM. Uma chave `source_ip` em `meta` **não** é promovida.
- O valor sai sempre como IP puro: `203.0.113.7:4711` → `203.0.113.7`, `[2001:db8::1]:8080` → `2001:db8::1`, `::ffff:10.0.0.1` → `10.0.0.1`. Um valor que não é IP (ex.: `X-Forwarded-For: unknown`) é ignorado e vale a fonte seguinte. O SIEM descarta, sem erro, `client_ip` com porta.

---

## Limitações conhecidas

- **Dado pessoal selado.** O IP do cliente (`client_ip` e `payload.context.ip`), o User-Agent e o path concreto vão para o bloco imutável e **não podem ser apagados depois**. Avalie isso contra a sua política de privacidade (IP é dado pessoal na LGPD). Não ponha dado sensível em path, nome de evento, trilha ou mensagem de exceção.
- **Path concreto no evento:** `payload.request.path` e o `event_name` padrão levam os IDs da URL (`/users/42`). `http_route` usa o template.
- **Entrega:** um POST síncrono por requisição no `terminate()`, sem retry e sem fila. O evento se perde em falha de rede ou se o ImmutableLog recusar.
- **`Idempotency-Key`** é gerado pelo middleware (UUID por evento). O `X-Request-Id` recebido não é usado: ele vem do chamador, e dois eventos diferentes com o mesmo valor seriam tratados pelo core como duplicata (`200 duplicate`).
- **Octane (Swoole/RoadRunner/FrankenPHP worker)** não foi testado.
