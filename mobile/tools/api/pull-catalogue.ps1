<#
  Dumps the server's own endpoint catalogue to _design/api/catalogue.json.
  READ-ONLY on the Laravel side: it runs one tinker expression that json_encodes
  App\Api\Catalogue::endpoints() — the list the routes are registered from and
  MOBILE_HANDOFF.md is written from — and, beside it, `live_types`: every live
  event type the server can emit (App\Live\LiveTypes, M11 Part C §8.4), which
  the generator turns into liveTypes.ts for the handler-type contract.
  Then run `npm run api:generate`.

    npm run api:pull                      the web repository beside this one
    powershell -File tools/api/pull-catalogue.ps1 -Laravel <path>   another checkout (a worktree)
#>
param([string]$Laravel = '')
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Resolve-Path (Join-Path $here '..\..')
$laravel = if ($Laravel -ne '') { $Laravel } else { Join-Path (Split-Path -Parent $root) 'geminisecure' }
$out = (Join-Path $root '_design\api\catalogue.json') -replace '\\', '/'
New-Item -ItemType Directory -Force (Join-Path $root '_design\api') | Out-Null
Push-Location $laravel
try {
    php artisan tinker --execute="file_put_contents('$out', json_encode(['endpoints' => App\Api\Catalogue::endpoints(), 'common_errors' => App\Api\Catalogue::COMMON_ERRORS, 'live_types' => class_exists(App\Live\LiveTypes::class) ? App\Live\LiveTypes::all() : []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));"
} finally { Pop-Location }
$n = ((Get-Content $out -Raw -Encoding UTF8 | ConvertFrom-Json).endpoints.PSObject.Properties | Measure-Object).Count
Write-Output "OK - $n endpoints written to _design/api/catalogue.json"
