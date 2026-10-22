# Stands up a real Android phone in software on this machine, so the on-device checks can run
# without a handset being plugged in.
#
# WHY THIS EXISTS. Every hardware check in PHONE_CHECK.md needs an Android that is really Android:
# the operating system's own permission dialogs, real notification channels, a real Do Not Disturb,
# a timezone that can be changed underneath the app, and a real installed build rather than a page
# in a browser. An emulator gives all of that. It is NOT a substitute for a handset - it cannot
# speak to a fingerprint sensor, a real camera, a SIM or a mobile network, and its rendering is the
# platform's own rather than a manufacturer's skin - so anything measured here is reported AS an
# emulator result and never as a device one.
#
# Everything downloads into the SDK folder this machine already has. Nothing is installed
# system-wide and nothing needs administrator rights.
#
#   powershell -ExecutionPolicy Bypass -File tools/device/setup-emulator.ps1
#   powershell -ExecutionPolicy Bypass -File tools/device/setup-emulator.ps1 -SkipDownloads

param([switch]$SkipDownloads)

# EVERY TOOL BELOW IS A NATIVE PROGRAM THAT WRITES PROGRESS TO THE ERROR STREAM. java, sdkmanager and
# avdmanager all do it, and under ErrorActionPreference=Stop PowerShell reads that as a failure and
# aborts a download that was working perfectly. So errors are non-fatal here and every step checks the
# thing it was supposed to produce instead.
$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'   # the progress bar makes Invoke-WebRequest an order of magnitude slower

$Sdk   = Join-Path $env:LOCALAPPDATA 'Android\Sdk'
$Tmp   = Join-Path $env:TEMP 'gs-android'
$Jdk   = Join-Path $Sdk 'jdk'
$Tools = Join-Path $Sdk 'cmdline-tools\latest'

New-Item -ItemType Directory -Force -Path $Sdk, $Tmp | Out-Null

function Say($m) { Write-Output ("[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m) }

# ── 1. a JDK, because sdkmanager is a Java program ────────────────────────────────────────────────
if (-not (Test-Path (Join-Path $Jdk 'bin\java.exe'))) {
    if (-not $SkipDownloads) {
        Say 'downloading a JDK (about 190 MB)'
        $url = 'https://api.adoptium.net/v3/binary/latest/21/ga/windows/x64/jdk/hotspot/normal/eclipse?project=jdk'
        Invoke-WebRequest -Uri $url -OutFile (Join-Path $Tmp 'jdk.zip') -UseBasicParsing
    }
    Say 'unpacking the JDK'
    $stage = Join-Path $Tmp 'jdk-stage'
    Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue
    Expand-Archive -Path (Join-Path $Tmp 'jdk.zip') -DestinationPath $stage -Force
    $inner = Get-ChildItem $stage -Directory | Select-Object -First 1
    Remove-Item $Jdk -Recurse -Force -ErrorAction SilentlyContinue
    Move-Item $inner.FullName $Jdk
}
$env:JAVA_HOME = $Jdk
$env:PATH = "$Jdk\bin;$env:PATH"
# `java -version` writes to STDERR even when it succeeds. Prove the JDK is there another way, because
# every tool below is a native program that also writes progress to the error stream.
$javaExe = Join-Path $Jdk 'bin\java.exe'
if (-not (Test-Path $javaExe)) { throw "the JDK did not unpack: $javaExe is missing" }
Say "java: $javaExe"

# ── 2. the Android command-line tools ─────────────────────────────────────────────────────────────
if (-not (Test-Path (Join-Path $Tools 'bin\sdkmanager.bat'))) {
    if (-not $SkipDownloads) {
        Say 'downloading the Android command-line tools (about 130 MB)'
        Invoke-WebRequest -Uri 'https://dl.google.com/android/repository/commandlinetools-win-11076708_latest.zip' -OutFile (Join-Path $Tmp 'cmdline.zip') -UseBasicParsing
    }
    Say 'unpacking the command-line tools'
    $stage = Join-Path $Tmp 'cmdline-stage'
    Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue
    Expand-Archive -Path (Join-Path $Tmp 'cmdline.zip') -DestinationPath $stage -Force
    New-Item -ItemType Directory -Force -Path (Split-Path $Tools) | Out-Null
    Remove-Item $Tools -Recurse -Force -ErrorAction SilentlyContinue
    Move-Item (Join-Path $stage 'cmdline-tools') $Tools
}
$sdkmanager = Join-Path $Tools 'bin\sdkmanager.bat'
$avdmanager = Join-Path $Tools 'bin\avdmanager.bat'

# ── 3. the emulator and a system image ────────────────────────────────────────────────────────────
#
# google_apis_playstore carries Google Play services, which is what actually DELIVERS a push. A plain
# google_apis image cannot receive one, and proving a push arrives at a closed app is the single
# reason this emulator is worth standing up at all.
$image = 'system-images;android-34;google_apis_playstore;x86_64'

Say 'accepting the SDK licences'
$yes = ("y`n" * 60)
$yes | & $sdkmanager --sdk_root="$Sdk" --licenses 2>&1 | Select-Object -Last 2

Say 'installing platform-tools, emulator and the Android 34 Play system image (about 1.6 GB - this is the long part)'
& $sdkmanager --sdk_root="$Sdk" 'platform-tools' 'emulator' 'platforms;android-34' $image 2>&1 | Select-Object -Last 6

# ── 4. the virtual phone itself ───────────────────────────────────────────────────────────────────
#
# A Pixel profile, because it is the reference geometry the design boards are closest to. 1080x2400
# at 420 dpi is a mainstream Android phone and close to the android-phone screenshot class.
$avd = 'geminisecure'
Say ("creating the virtual phone '{0}'" -f $avd)
'no' | & $avdmanager create avd --force --name $avd --package $image --device 'pixel_6' 2>&1 | Select-Object -Last 3

$config = Join-Path $env:USERPROFILE ".android\avd\$avd.avd\config.ini"
if (Test-Path $config) {
    # Enough memory and storage that installing two development builds does not fill it, and a fixed
    # screen so a measurement taken today can be compared with one taken next week.
    $set = @{
        'hw.lcd.width'  = '1080'
        'hw.lcd.height' = '2400'
        'hw.lcd.density' = '420'
        'hw.ramSize'    = '4096'
        'disk.dataPartition.size' = '8192'
        'hw.keyboard'   = 'yes'
    }
    $lines = Get-Content $config | Where-Object { $k = ($_ -split '=')[0].Trim(); -not $set.ContainsKey($k) }
    foreach ($k in $set.Keys) { $lines += "$k=$($set[$k])" }
    [IO.File]::WriteAllLines($config, $lines)
    Say 'screen, memory and storage set'
}

Say 'DONE. Start it with: tools/device/start-emulator.ps1'
