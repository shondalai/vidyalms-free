# Copyright (C) 2026 BulaSikku Technologies Private Limited.
# SPDX-License-Identifier: GPL-3.0-or-later
# Restrict only the dedicated private .secrets directory; never traverse a tree.
param(
    [Parameter(Mandatory = $true)][string]$Directory,
    [switch]$VerifyOnly
)

$ErrorActionPreference = 'Stop'
try {
    $item = Get-Item -LiteralPath $Directory -Force
    if (-not $item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
        throw 'An ordinary key directory is required.'
    }
    $owner = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $identities = @($owner, [Security.Principal.SecurityIdentifier]::new('S-1-5-18'), [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544'))
    $allowed = @($identities | ForEach-Object { $_.Value } | Select-Object -Unique)
    $existing = [IO.Directory]::GetAccessControl($item.FullName)
    if ($allowed -notcontains $existing.GetOwner([Security.Principal.SecurityIdentifier]).Value) {
        throw 'Untrusted key directory owner.'
    }
    if (-not $VerifyOnly) {
        $folderAcl = [Security.AccessControl.DirectorySecurity]::new()
        $folderAcl.SetAccessRuleProtection($true, $false)
        # Retain the trusted owner: rewriting ownership needs additional rights.
        foreach ($sid in $identities) {
            $folderAcl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
        }
        [IO.Directory]::SetAccessControl($item.FullName, $folderAcl)
    }
    $actual = [IO.Directory]::GetAccessControl($item.FullName)
    if (-not $actual.AreAccessRulesProtected) { throw 'Key directory inheritance is not protected.' }
    if ($allowed -notcontains $actual.GetOwner([Security.Principal.SecurityIdentifier]).Value) { throw 'Untrusted key directory owner.' }
    $granted = @()
    foreach ($rule in $actual.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
        if ($rule.AccessControlType -ne 'Allow' -or $allowed -notcontains $rule.IdentityReference.Value) {
            throw 'Unexpected key directory access.'
        }
        if (($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -eq [Security.AccessControl.FileSystemRights]::FullControl -and
            ($rule.InheritanceFlags -band [Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit') -eq [Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit' -and
            $rule.PropagationFlags -eq [Security.AccessControl.PropagationFlags]::None) {
            $granted += $rule.IdentityReference.Value
        }
    }
    foreach ($sid in $allowed) {
        if ($granted -notcontains $sid) { throw 'Required key directory access is missing.' }
    }
    exit 0
} catch {
    # No path, key or low-level exception details are emitted into a web request.
    Write-Error 'VidyaLMS invitation key directory ACL validation failed.' -ErrorAction Continue
    exit 1
}
