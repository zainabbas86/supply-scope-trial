<#
.SYNOPSIS
    Creates the uat / staging / production GitHub Environments and populates
    their secrets and variables.

.DESCRIPTION
    Idempotent. `gh secret set` and `gh variable set` are upserts, so an
    existing value is REPLACED and a missing one created; creating the
    environment is a PUT. Re-running is therefore safe and this doubles as the
    rotation tool. Nothing is written back to the repository.

    Two things it does NOT do:

      - It never DELETES. A secret or variable you set once and later removed
        from this script stays behind in the environment. Remove those by hand:
            gh secret list --env production
            gh variable delete OLD_NAME --env production

      - It does not replace an APP_KEY that already exists, because that is the
        one value where an upsert is destructive rather than merely updating.
        Pass -RotateAppKey when you actually mean to.

    Secrets come from your LOCAL .env (gitignored) or are generated here. They
    are passed to `gh` on stdin rather than as arguments, so they never appear
    in the PowerShell command history or in a process list.

    APP_KEY is generated fresh PER ENVIRONMENT. Sharing one would mean a session
    cookie minted in uat is valid in production - they sign the same things.

.EXAMPLE
    Run from the repository root, in a LOCAL shell - not in a container and not
    in CI. It reads the real secrets from your gitignored .env, which exists
    only on this machine.

    .\scripts\setup-github-envs.ps1 -WhatIf
    .\scripts\setup-github-envs.ps1
    .\scripts\setup-github-envs.ps1 -Environments production

    Windows PowerShell 5.1 is fine; so is pwsh 7 if you have it. `php` must be
    on PATH, because APP_KEY is generated with `php artisan key:generate`.
#>

# NOTE ON ENCODING
# This file is saved as UTF-8 WITH BOM and uses ASCII only.
#
# Windows PowerShell 5.1 reads a .ps1 without a BOM as ANSI (Windows-1252), so
# a UTF-8 em-dash in a comment arrives as two bytes and the parser reports
# "The string is missing the terminator" on a line nowhere near the real one.
# The BOM makes 5.1 read it as UTF-8; keeping to ASCII means it does not matter
# either way.

[CmdletBinding(SupportsShouldProcess)]
param(
    [string[]] $Environments = @('uat', 'staging', 'production'),
    [string]   $EnvFile = '.env',

    # Replace an APP_KEY that already exists. Off by default: rotating it logs
    # every session out and makes anything encrypted with the old key
    # unreadable, which is not something a re-run should do by accident.
    [switch]   $RotateAppKey,

    # The PRIVATE half of the key whose public half is in the VPS's
    # authorized_keys. GitHub Actions uses it to SSH in and deploy.
    #
    # Note the file has no .pub extension. ssh wants the PRIVATE key and derives
    # the public one; pointing any IdentityFile at a .pub is a common slip that
    # fails with "Permission denied (publickey)" and no hint as to why.
    [string]   $SshKeyPath = "$env:USERPROFILE\.ssh\hostinger_zainabbas_vps"
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# -----------------------------------------------------------------------------
# Preflight
# -----------------------------------------------------------------------------

if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    # Not a here-string: Windows PowerShell 5.1 parses here-strings
    # unreliably from LF-only files, and this repo enforces LF via
    # .gitattributes. An array joined with newlines is unambiguous.
    Write-Error ((
        'GitHub CLI (gh) is not installed.',
        '',
        '    winget install --id GitHub.cli',
        '',
        'Then authenticate:',
        '',
        '    gh auth login'
    ) -join [Environment]::NewLine)
}

# `gh auth status` exits non-zero when signed out.
gh auth status *> $null
if ($LASTEXITCODE -ne 0) { Write-Error 'Not signed in. Run: gh auth login' }

if (-not (Test-Path $EnvFile)) {
    Write-Error "$EnvFile not found. Copy .env.example to .env and fill it in first."
}

$repo = (gh repo view --json nameWithOwner --jq .nameWithOwner).Trim()
Write-Host "Repository : $repo"
Write-Host "Environments: $($Environments -join ', ')`n"

# -----------------------------------------------------------------------------
# Read the local .env
# -----------------------------------------------------------------------------

function Get-EnvValue([string] $Key) {
    $line = Select-String -Path $EnvFile -Pattern "^$Key=" | Select-Object -First 1
    if (-not $line) { return $null }

    # Split on the FIRST '=' only: a value may legitimately contain more.
    $value = ($line.Line -split '=', 2)[1]
    return $value.Trim().Trim('"')
}

$openAiKey = Get-EnvValue 'OPENAI_API_KEY'
if ([string]::IsNullOrWhiteSpace($openAiKey)) {
    Write-Error "OPENAI_API_KEY is empty in $EnvFile."
}

# -----------------------------------------------------------------------------
# What goes where
#
# The test is not "is this config?" but "would seeing it in a run log matter?".
# Variables are visible to anyone who can read the repository; secrets are
# masked and write-only.
# -----------------------------------------------------------------------------

# The deploy key. Read from disk rather than from .env: a multi-line PEM does
# not survive a dotenv file, and the private key has no business being there.
$sshKey = $null
if (Test-Path $SshKeyPath) {
    $sshKey = (Get-Content $SshKeyPath -Raw)
} else {
    Write-Warning "No SSH key at $SshKeyPath - SSH_PRIVATE_KEY will be skipped."
    Write-Warning "  ssh-keygen -t ed25519 -C 'supplyscope-vps' -f '$SshKeyPath'"
}

$sharedSecrets = @{
    DB_PASSWORD     = (Get-EnvValue 'DB_PASSWORD')
    REDIS_PASSWORD  = (Get-EnvValue 'REDIS_PASSWORD')
    ADMIN_PASSWORD  = (Get-EnvValue 'ADMIN_PASSWORD')
    OPENAI_API_KEY  = $openAiKey
    SSH_PRIVATE_KEY = $sshKey
}

# Non-sensitive, and genuinely different per environment. Anything IDENTICAL
# everywhere belongs in config/ or .env.example instead - three copies of the
# same value is three chances to drift.
#
# FILESYSTEM_DISK is 'local', not 's3'.
#
# Web and worker are separate containers, and the worker must read the file the
# web container wrote. On a single host they share a docker volume, so the local
# disk is genuinely shared and 's3' would be a lie: league/flysystem-aws-s3-v3
# is not installed, so the driver would fail on the first upload.
#
# It becomes 's3' only if web and worker are ever split across machines - a
# platform where volumes attach to one machine (Fly, Render, Cloud Run). That is
# a package install and a bucket, not a config flip.
#
# There are no DB_HOST / DB_PORT / REDIS_HOST / REDIS_PORT variables.
#
# They used to be here and they were dead: docker-compose.yml overrides all
# four to the compose service names, because that is the only thing that works
# on a single host. A variable that looks like configuration but changes
# nothing is worse than an absent one - someone eventually edits it, sees no
# effect, and goes looking for the bug somewhere real.
$variables = @{
    uat = @{
        APP_ENV = 'production'; APP_DEBUG = 'false'; LOG_LEVEL = 'debug'
        APP_URL = 'https://uat.example.com'; APP_DOMAIN = 'uat.example.com'
        SSH_HOST = 'CHANGE-ME'; SSH_USER = 'root'; SSH_PORT = '22'
        DEPLOY_DIR = '/srv/supplyscope'
        DB_DATABASE = 'label_extractor'; DB_USERNAME = 'label_extractor'
        ADMIN_USERNAME = 'admin'
        FILESYSTEM_DISK = 'local'
        OPENAI_MODEL = 'gpt-5.5'
        # A low ceiling here means a runaway test cannot spend production budget.
        EXTRACTION_DAILY_LIMIT = '25'
    }
    staging = @{
        APP_ENV = 'production'; APP_DEBUG = 'false'; LOG_LEVEL = 'info'
        APP_URL = 'https://staging.example.com'; APP_DOMAIN = 'staging.example.com'
        SSH_HOST = 'CHANGE-ME'; SSH_USER = 'root'; SSH_PORT = '22'
        DEPLOY_DIR = '/srv/supplyscope'
        DB_DATABASE = 'label_extractor'; DB_USERNAME = 'label_extractor'
        ADMIN_USERNAME = 'admin'
        FILESYSTEM_DISK = 'local'
        OPENAI_MODEL = 'gpt-5.5'
        EXTRACTION_DAILY_LIMIT = '100'
    }
    production = @{
        # APP_ENV=production everywhere, including uat: it is what disables
        # debug pages and enables production error handling. "Which deployment
        # is this?" is answered by APP_URL, not by pretending uat is local.
        APP_ENV = 'production'; APP_DEBUG = 'false'; LOG_LEVEL = 'warning'

        # Three forms of the same host, and they are not interchangeable:
        #   APP_URL         what Laravel builds absolute links with - needs
        #                   the scheme
        #   APP_DOMAIN      the ONE canonical hostname, used for the health
        #                   probe's URL - must not have a scheme
        #   APP_SERVER_NAME every hostname Caddy should hold a certificate
        #                   for, space-separated
        #
        # www is a CNAME to the apex, so it resolves to this box whether or not
        # anyone intended it. Leaving it out of APP_SERVER_NAME does not give
        # visitors a 404 - it gives them a browser TLS warning, which is worse
        # and looks broken.
        APP_URL = 'https://zainabbas.com.au'
        APP_DOMAIN = 'zainabbas.com.au'
        APP_SERVER_NAME = 'zainabbas.com.au www.zainabbas.com.au'

        SSH_HOST = '31.97.71.13'; SSH_USER = 'root'; SSH_PORT = '22'
        DEPLOY_DIR = '/srv/supplyscope'
        DB_DATABASE = 'label_extractor'; DB_USERNAME = 'label_extractor'
        ADMIN_USERNAME = 'admin'
        FILESYSTEM_DISK = 'local'
        OPENAI_MODEL = 'gpt-5.5'
        EXTRACTION_DAILY_LIMIT = '500'
    }
}

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------

# SupportsShouldProcess on the FUNCTION, not only on the script. Without it
# $PSCmdlet here resolves to the script's, through dynamic scoping - which
# happens to work and is not something to rely on. Declared, each function has
# its own, and -WhatIf still reaches it through $WhatIfPreference.
function Set-EnvSecret {
    [CmdletBinding(SupportsShouldProcess)]
    param([string] $Env, [string] $Name, [string] $Value)

    if ([string]::IsNullOrWhiteSpace($Value)) {
        Write-Warning "  ! $Name is empty - skipped"
        return
    }
    if (-not $PSCmdlet.ShouldProcess("$Env/$Name", 'set secret')) { return }

    # stdin, not an argument: keeps the value out of shell history and out of
    # any process listing.
    $Value | gh secret set $Name --env $Env --body -
    if ($LASTEXITCODE -ne 0) { Write-Error "Failed to set secret $Name for $Env" }
    Write-Host "  secret   $Name"
}

function Set-EnvVariable {
    [CmdletBinding(SupportsShouldProcess)]
    param([string] $Env, [string] $Name, [string] $Value)

    if (-not $PSCmdlet.ShouldProcess("$Env/$Name", 'set variable')) { return }

    gh variable set $Name --env $Env --body $Value | Out-Null
    if ($LASTEXITCODE -ne 0) { Write-Error "Failed to set variable $Name for $Env" }

    $note = if ($Value -eq 'CHANGE-ME') { '   <-- CHANGE THIS' } else { '' }
    Write-Host "  variable $Name = $Value$note"
}

# -----------------------------------------------------------------------------
# Apply
# -----------------------------------------------------------------------------

foreach ($env in $Environments) {
    Write-Host "`n=== $env ===" -ForegroundColor Cyan

    # Creating an environment is a PUT, so this is idempotent.
    if ($PSCmdlet.ShouldProcess($env, 'create environment')) {
        gh api -X PUT "repos/$repo/environments/$env" --silent
        if ($LASTEXITCODE -ne 0) { Write-Error "Could not create environment $env" }
        Write-Host "  environment created / confirmed"
    }

    # A DIFFERENT key per environment, generated here and never stored locally.
    #
    # Generated ONLY when the environment does not already have one. Everything
    # else in this script is an upsert and re-running is harmless, but APP_KEY
    # is not that kind of value: replacing it invalidates every session and
    # makes anything encrypted with the old key unreadable. A tool you re-run
    # to fix one variable must not quietly do that.
    $hasAppKey = $false
    $existing = gh secret list --env $env 2>$null
    if ($LASTEXITCODE -eq 0 -and $existing) {
        $hasAppKey = [bool]($existing | Select-String -Pattern '^APP_KEY\s' -Quiet)
    }

    if ($RotateAppKey -or -not $hasAppKey) {
        $appKey = (php artisan key:generate --show).Trim()
        Set-EnvSecret $env 'APP_KEY' $appKey
    } else {
        Write-Host '  secret   APP_KEY (kept - pass -RotateAppKey to replace it)'
    }

    foreach ($name in $sharedSecrets.Keys | Sort-Object) {
        Set-EnvSecret $env $name $sharedSecrets[$name]
    }

    # No host yet, so there is nothing real to put here. The deploy job checks
    # for it and fails with a clear message rather than half-deploying.
    # SSH_KNOWN_HOSTS cannot be generated here: it is the server's own host
    # key, and the server may not exist yet. Left unset the deploy still runs,
    # but trusts whatever answers on first connect.
    Write-Warning '  ! SSH_KNOWN_HOSTS not set - run: ssh-keyscan -H <ip>'

    foreach ($name in $variables[$env].Keys | Sort-Object) {
        Set-EnvVariable $env $name $variables[$env][$name]
    }
}

Write-Host ((
    '',
    'Done.',
    '',
    'Next:',
    '  1. Set SSH_HOST to the VPS IP:',
    "       gh variable set SSH_HOST --env production --body '203.0.113.10'",
    '  2. Pin the host key, so the deploy cannot be tricked into handing',
    '     its private key to an impostor:',
    "       ssh-keyscan -H <ip> | gh variable set SSH_KNOWN_HOSTS --env production",
    '  3. Confirm APP_URL and APP_DOMAIN match the DNS record you created.',
    '  4. Protect production:',
    '       Settings -> Environments -> production -> Required reviewers',
    '',
    'Verify:',
    '  gh secret list --env production',
    '  gh variable list --env production'
) -join [Environment]::NewLine) -ForegroundColor Green
