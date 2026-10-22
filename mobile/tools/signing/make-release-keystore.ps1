# THE GEMINISECURE RELEASE KEY, MADE ONCE (M33 §2).
#
#   powershell -NoProfile -File tools/signing/make-release-keystore.ps1
#
# One keystore, two keys: `guard` signs GeminiSecure Guard and `resident` signs GeminiSecure Resident. RSA 4096, valid
# 10,000 days, "CN=GeminiSecure, O=Gemini Security Limited". Written OUTSIDE every repository, to
#   %USERPROFILE%\.geminisecure-signing\geminisecure-release.jks     the keystore (PKCS12)
#   %USERPROFILE%\.geminisecure-signing\signing.properties           where it is, and its password
# both readable by this Windows account alone. The password is generated here and NEVER PRINTED: open
# signing.properties yourself to copy it into a password manager.
#
# REFUSES if either file is already there. A second key is a second identity: every phone would have to uninstall to
# take an app signed with it, and an app published under the first could never be updated by the second.
#
# It backs nothing up. That is the owner's to do — see the warning it prints.
#
# keytool and icacls run through the .NET process API, not PowerShell's `&`: Windows PowerShell 5.1 turns a native
# program's progress on stderr into a terminating error, which stopped the first attempt before a key was written.
$ErrorActionPreference = 'Stop'

$dir = Join-Path $env:USERPROFILE '.geminisecure-signing'
$store = Join-Path $dir 'geminisecure-release.jks'
$props = Join-Path $dir 'signing.properties'
$keytool = Join-Path $env:LOCALAPPDATA 'Android\Sdk\jdk\bin\keytool.exe'
$dname = 'CN=GeminiSecure, O=Gemini Security Limited'

function Invoke-Native([string]$exe, [string]$arguments) {
    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = $exe
    $info.Arguments = $arguments
    $info.UseShellExecute = $false
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $process = [System.Diagnostics.Process]::Start($info)
    $out = $process.StandardOutput.ReadToEnd()
    [void]$process.StandardError.ReadToEnd()
    $process.WaitForExit()
    return @{ Code = $process.ExitCode; Out = $out }
}

if ((Test-Path $store) -or (Test-Path $props)) {
    Write-Output "REFUSED: $dir already holds a release key. Making another would make a second identity; nothing was changed."
    exit 1
}
if (-not (Test-Path $keytool)) { Write-Output "REFUSED: no keytool at $keytool"; exit 1 }

New-Item -ItemType Directory -Force $dir | Out-Null

# 32 characters from a cryptographic generator: letters and digits, so no shell or properties file ever mangles it.
$alphabet = [char[]]'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
$bytes = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$password = -join ($bytes | ForEach-Object { $alphabet[$_ % $alphabet.Length] })

# Handed to keytool through the environment (-storepass:env), never on a command line a process list could show.
$env:GS_NEW_KEYSTORE_SECRET = $password
try {
    foreach ($alias in 'guard', 'resident') {
        $made = Invoke-Native $keytool ("-genkeypair -keystore `"$store`" -storetype PKCS12 -alias $alias -keyalg RSA -keysize 4096 -validity 10000 -dname `"$dname`" -storepass:env GS_NEW_KEYSTORE_SECRET -keypass:env GS_NEW_KEYSTORE_SECRET")
        if ($made.Code -ne 0) { throw "keytool could not make the $alias key (exit $($made.Code))" }
    }

    # PKCS12 keeps one password for the store and its keys.
    $body = @(
        '# GeminiSecure release signing (M33 §2). NEVER commit, email, upload or print this file.',
        "storeFile=$($store.Replace('\', '/'))",
        "storePassword=$password",
        "keyPassword=$password",
        'guardAlias=guard',
        'residentAlias=resident'
    ) -join "`n"
    [IO.File]::WriteAllText($props, $body + "`n", (New-Object System.Text.UTF8Encoding $false))

    # This account alone: no inherited access, full control for the owner.
    foreach ($file in $store, $props) {
        $locked = Invoke-Native 'icacls.exe' ("`"$file`" /inheritance:r /grant:r `"$($env:USERNAME):F`"")
        if ($locked.Code -ne 0) { throw "icacls could not restrict $file (exit $($locked.Code))" }
    }

    Write-Output "Made: $store"
    Write-Output "      $props (the password: open it yourself; it is not printed)"
    foreach ($alias in 'guard', 'resident') {
        $listed = Invoke-Native $keytool ("-list -v -keystore `"$store`" -storetype PKCS12 -alias $alias -storepass:env GS_NEW_KEYSTORE_SECRET")
        if ($listed.Code -ne 0) { throw "keytool could not read back the $alias key (exit $($listed.Code))" }
        $sha = [regex]::Match($listed.Out, 'SHA256:\s*([0-9A-F:]+)').Groups[1].Value
        $owner = [regex]::Match($listed.Out, '(?m)^Owner:\s*(.+?)\s*$').Groups[1].Value
        $until = [regex]::Match($listed.Out, 'until:\s*(.+?)\s*$', 'Multiline').Groups[1].Value
        Write-Output ("{0,-9} SHA-256 {1}  {2}  valid until {3}" -f $alias, $sha, $owner, $until)
    }
    Write-Output ''
    Write-Output 'If this keystore file or its password is lost, the GeminiSecure apps can never be updated again. Every phone would have to uninstall and reinstall, and the apps could never be published under the same identity. Back it up in at least two places, one of them not on this laptop, and store the password in a password manager.'
} finally {
    Remove-Item Env:\GS_NEW_KEYSTORE_SECRET -ErrorAction SilentlyContinue
    $password = $null
}
