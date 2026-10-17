# AI Router

The `POST /api/chat` endpoint is implemented with LavaLust routes and controllers. Provider credentials and URLs are read only from the backend environment. Conversations and per-IP rate-limit counters are stored as JSON files under `runtime/ai_router`; the existing `runtime/.htaccess` denies web access to that directory. No database assumptions or extra packages are required.

## Configuration

No Composer packages or database migrations are required. If you do not already have a local `.env`, copy the example:

```powershell
Copy-Item .env.example .env
```

1. Set `AI_ROUTER_TOKEN` in `.env` to a random secret at least 32 characters long. Generate one with PowerShell:

   ```powershell
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

2. Set `GROQ_API_KEY` and/or `MISTRAL_API_KEY` in `.env` using newly generated credentials. Do not use keys exposed in chat or commit `.env`.
3. Configure model names if the defaults are unavailable to your account.
4. Ensure Ollama is listening on loopback at `http://localhost:11434` and that `llama3.2:3b` is installed. Do not bind Ollama to a public interface.
5. Restart Apache/PHP after environment changes if Laragon is not reloading `.env`.

The router sends `Authorization: Bearer <AI_ROUTER_TOKEN>` to this endpoint. Do not embed that shared server token in a public React bundle. For a deployed browser frontend, put the API call behind your existing authenticated backend/session flow or a trusted server-side proxy. The endpoint fails closed if the token is missing or shorter than 32 characters.

For direct local browser calls from React, allow the exact React origin in `app/config/config.php` under `$config['security_headers']['cors']['allowed_origins']` (for example, `http://localhost:5173`). Do not use `*` for a deployed app. CORS is not authentication; do not expose the shared router token in a production browser bundle.

`AI_ENABLE_FALLBACK=true` enables fallback for automatic routing. `provider: "auto"` uses `AI_PROVIDER_PRIORITY` (default `groq,mistral,ollama`). A manually selected provider is not switched unless the request includes `"fallback": true`. Credentials/configuration errors and invalid provider requests are not retried on another provider. Rate limits, timeouts, and provider outages can fall through to the next provider; the router does not sleep and replay a request during the same call, and returns a bounded `retry_after` value if the last provider supplied one.

Conversation history is limited to `AI_MAX_HISTORY_TURNS` user/assistant turns (default 10). The system prompt is independently configured by `AI_SYSTEM_PROMPT`. Requests default to a 16 KiB maximum body, a 20 requests per 60 seconds per-IP rate limit, and a maximum provider timeout of 120 seconds.

## Request

```json
{
  "message": "Explain Laravel MVC",
  "provider": "auto",
  "conversation_id": null
}
```

Optional `"fallback": true` allows fallback from a manually selected provider, subject to `AI_ENABLE_FALLBACK`.

Successful response:

```json
{
  "success": true,
  "provider": "ollama",
  "model": "llama3.2:3b",
  "conversation_id": "generated-uuid",
  "message": "..."
}
```

## PowerShell tests

Run from PowerShell. Supply the router token and (for provider-specific requests) test credentials in local environment variables rather than placing actual provider credentials in shell history. Replace `REPLACE_WITH_A_RANDOM_32_CHARACTER_OR_LONGER_TOKEN` in the commands with the value configured in `.env`.

```powershell
$uri = "http://127.0.0.1:3000/api/chat"
$headers = @{ Authorization = "Bearer REPLACE_WITH_A_RANDOM_32_CHARACTER_OR_LONGER_TOKEN" }
$headers["Content-Type"] = "application/json"
```

Test each provider independently before testing automatic routing:

```powershell
$body = @{ message = "Say hello in one sentence."; provider = "groq" } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -Body $body

$body = @{ message = "Say hello in one sentence."; provider = "mistral" } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -Body $body

$body = @{ message = "Say hello in one sentence."; provider = "ollama" } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -Body $body
```

Automatic routing and conversation continuity:

```powershell
$body = @{ message = "Remember the word violet."; provider = "auto"; conversation_id = $null } | ConvertTo-Json
$first = Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -Body $body
$first

$body = @{ message = "What word did I ask you to remember?"; provider = "auto"; conversation_id = $first.conversation_id } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -Body $body
```

Fallback after a rate limit: set `AI_PROVIDER_PRIORITY=groq,mistral,ollama`, configure all three, then temporarily use a Groq account/model that returns HTTP 429 or reach its quota. Send an automatic request and confirm the response's `provider` is Mistral or Ollama. The router does not provide a fake rate-limit switch.

```powershell
$body = @{ message = "Test provider fallback."; provider = "auto"; conversation_id = $null } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -Body $body
```

Fallback when Ollama is offline: set `AI_PROVIDER_PRIORITY=ollama,groq,mistral`, stop the local Ollama service, and send an automatic request. Confirm it succeeds through Groq or Mistral. Restore Ollama and the preferred priority afterward.

```powershell
$body = @{ message = "Test fallback when Ollama is offline."; provider = "auto" } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -Body $body
```

Invalid provider selection:

```powershell
$body = @{ message = "Test"; provider = "unknown" } | ConvertTo-Json
try {
    Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -Body $body
} catch {
    $_.ErrorDetails.Message
}
```

Missing API key: set `GROQ_API_KEY=` in `.env` and restart the PHP server, then send a Groq-only request. The response is a structured provider configuration error.

```powershell
$body = @{ message = "Test missing API key handling."; provider = "groq" } | ConvertTo-Json
try {
    Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -Body $body
} catch {
    $_.ErrorDetails.Message
}
```

Provider keys are never returned to clients. Provider-specific error messages are deliberately normalized; response codes identify configuration, invalid-request, rate-limit, and availability failures without exposing raw provider responses.
