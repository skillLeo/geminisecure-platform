# Is each native patch (tools/patches/apply.mjs) really INSIDE a built APK? (M22, D-217)
#
# Expo links most modules from prebuilt libraries, so a patch to their source can pass `npm run gate:patches` and
# still be missing from the app. This reads the APK's dex files and looks for text only the patched code carries.
#   powershell -File tools/patches/check-apk.ps1 -Apk apps/guard/android/app/build/outputs/apk/release/app-release.apk
# Exit 0 when every marker is there; 1 (and the missing ones named) when not.
param([Parameter(Mandatory = $true)][string]$Apk)

# The expo-task-manager patch adds a method of its own; the other patches change a line inside an existing method and
# leave no text of their own in the dex, so they are proven with it: all of them come from the same buildFromSource.
$markers = [ordered]@{
    'expo-task-manager keeps the task manager of an app started headless' = 'isHostHeldByActivity'
}

if (-not (Test-Path $Apk)) { Write-Output "NO APK at $Apk"; exit 1 }
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead((Resolve-Path $Apk).Path)
$text = New-Object System.Text.StringBuilder
try {
    foreach ($entry in $zip.Entries | Where-Object { $_.Name -like 'classes*.dex' }) {
        $buffer = New-Object System.IO.MemoryStream
        $stream = $entry.Open()
        $stream.CopyTo($buffer)
        $stream.Close()
        [void]$text.Append([System.Text.Encoding]::ASCII.GetString($buffer.ToArray()))
    }
} finally { $zip.Dispose() }

$all = $text.ToString()
$failed = $false
foreach ($name in $markers.Keys) {
    if ($all.Contains($markers[$name])) { Write-Output "IN APK   $name" }
    else { Write-Output "MISSING  $name ('$($markers[$name])' is not in the dex)"; $failed = $true }
}
if ($failed) { exit 1 }
