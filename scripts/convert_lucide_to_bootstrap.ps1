$ErrorActionPreference = 'Stop'

$iconMap = @{
    'archive' = 'bi-archive'
    'arrow-down' = 'bi-arrow-down'
    'arrow-down-0-1' = 'bi-sort-numeric-down'
    'arrow-left' = 'bi-arrow-left'
    'arrow-right' = 'bi-arrow-right'
    'arrow-up-down' = 'bi-arrow-down-up'
    'asterisk' = 'bi-asterisk'
    'award' = 'bi-award'
    'backpack' = 'bi-backpack'
    'bell' = 'bi-bell'
    'bell-ring' = 'bi-bell-fill'
    'badge-check' = 'bi-patch-check'
    'book-marked' = 'bi-bookmark-check'
    'book-open' = 'bi-book'
    'book-plus' = 'bi-journal-plus'
    'bookmark' = 'bi-bookmark'
    'bookmark-check' = 'bi-bookmark-check'
    'briefcase' = 'bi-briefcase'
    'building' = 'bi-building'
    'building-2' = 'bi-buildings'
    'calendar-check' = 'bi-calendar-check'
    'calendar-days' = 'bi-calendar3'
    'calendar-plus' = 'bi-calendar-plus'
    'calendar-range' = 'bi-calendar-range'
    'calendar-x' = 'bi-calendar-x'
    'camera' = 'bi-camera'
    'chart-line' = 'bi-graph-up-arrow'
    'check' = 'bi-check'
    'check-check' = 'bi-check2-all'
    'chevron-down' = 'bi-chevron-down'
    'chevron-left' = 'bi-chevron-left'
    'chevron-right' = 'bi-chevron-right'
    'circle' = 'bi-circle-fill'
    'circle-alert' = 'bi-exclamation-circle-fill'
    'circle-check' = 'bi-check-circle-fill'
    'circle-help' = 'bi-question-circle-fill'
    'circle-minus' = 'bi-dash-circle-fill'
    'circle-pause' = 'bi-pause-circle-fill'
    'circle-play' = 'bi-play-circle-fill'
    'circle-plus' = 'bi-plus-circle-fill'
    'circle-slash' = 'bi-slash-circle-fill'
    'circle-user' = 'bi-person-circle'
    'circle-x' = 'bi-x-circle-fill'
    'clipboard' = 'bi-clipboard'
    'clipboard-check' = 'bi-clipboard-check'
    'clipboard-list' = 'bi-card-checklist'
    'clipboard-x' = 'bi-clipboard-x'
    'clock-3' = 'bi-clock'
    'cloud-download' = 'bi-cloud-download'
    'cloud-upload' = 'bi-cloud-upload'
    'corner-down-left' = 'bi-arrow-return-left'
    'database' = 'bi-database'
    'door-open' = 'bi-door-open'
    'download' = 'bi-download'
    'eye' = 'bi-eye'
    'eye-off' = 'bi-eye-slash'
    'file-check' = 'bi-file-earmark-check'
    'file-down' = 'bi-file-earmark-arrow-down'
    'file-spreadsheet' = 'bi-file-earmark-spreadsheet'
    'file-text' = 'bi-file-earmark-text'
    'filter' = 'bi-funnel'
    'folder' = 'bi-folder'
    'folder-check' = 'bi-folder-check'
    'folder-open' = 'bi-folder2-open'
    'gauge' = 'bi-speedometer2'
    'globe' = 'bi-globe2'
    'graduation-cap' = 'bi-mortarboard'
    'grid-3x3' = 'bi-grid-3x3-gap'
    'headphones' = 'bi-headphones'
    'history' = 'bi-clock-history'
    'hourglass' = 'bi-hourglass-split'
    'house' = 'bi-house'
    'id-card' = 'bi-person-vcard'
    'inbox' = 'bi-inbox'
    'info' = 'bi-info-circle'
    'key' = 'bi-key'
    'landmark' = 'bi-bank'
    'layers' = 'bi-layers'
    'layers-3' = 'bi-layers'
    'layout-grid' = 'bi-grid'
    'lightbulb' = 'bi-lightbulb'
    'link' = 'bi-link-45deg'
    'list' = 'bi-list'
    'list-check' = 'bi-list-check'
    'lock' = 'bi-lock'
    'lock-open' = 'bi-unlock'
    'log-in' = 'bi-box-arrow-in-right'
    'log-out' = 'bi-box-arrow-right'
    'mail' = 'bi-envelope'
    'mail-open' = 'bi-envelope-open'
    'map-pin' = 'bi-geo-alt'
    'maximize' = 'bi-arrows-fullscreen'
    'message-circle' = 'bi-chat-dots'
    'message-square-text' = 'bi-chat-square-text'
    'network' = 'bi-diagram-3'
    'notebook-pen' = 'bi-journal-plus'
    'notebook-text' = 'bi-journal-text'
    'paperclip' = 'bi-paperclip'
    'pencil' = 'bi-pencil'
    'pencil-line' = 'bi-pencil-square'
    'plus' = 'bi-plus'
    'printer' = 'bi-printer'
    'qr-code' = 'bi-qr-code'
    'rotate-ccw' = 'bi-arrow-counterclockwise'
    'save' = 'bi-save'
    'scan-qr-code' = 'bi-qr-code-scan'
    'search' = 'bi-search'
    'send' = 'bi-send'
    'settings' = 'bi-gear'
    'settings-2' = 'bi-sliders'
    'shield' = 'bi-shield'
    'shield-check' = 'bi-shield-check'
    'smartphone' = 'bi-phone'
    'square-check' = 'bi-check2-square'
    'star' = 'bi-star-fill'
    'tag' = 'bi-tag'
    'thumbs-up' = 'bi-hand-thumbs-up-fill'
    'trash-2' = 'bi-trash'
    'triangle-alert' = 'bi-exclamation-triangle-fill'
    'trophy' = 'bi-trophy'
    'upload' = 'bi-upload'
    'user' = 'bi-person'
    'user-check' = 'bi-person-check'
    'user-cog' = 'bi-person-gear'
    'user-plus' = 'bi-person-plus'
    'users' = 'bi-people'
    'x' = 'bi-x-lg'
    'zap' = 'bi-lightning-charge'
}

$iconPattern = [regex]'(?is)<i(?<attrs1>[^>]*?)\sdata-lucide="(?<icon>[^"]+)"(?<attrs2>[^>]*)>\s*</i>'
$classPattern = [regex]'class=(?<q>["''])(?<classes>.*?)\k<q>'

$updatedFiles = 0
$updatedTags = 0

Get-ChildItem -Path 'templates' -Recurse -Filter '*.twig' | ForEach-Object {
    $path = $_.FullName
    $content = Get-Content -Path $path -Raw

    $fileTagCount = 0

    $newContent = $iconPattern.Replace($content, {
        param($m)

        $fileTagCount++

        $iconName = $m.Groups['icon'].Value.Trim()
        $biClass = if ($iconMap.ContainsKey($iconName)) { $iconMap[$iconName] } else { 'bi-circle-fill' }

        $attrs = ($m.Groups['attrs1'].Value + $m.Groups['attrs2'].Value)
        $attrs = $attrs -replace '\sdata-lucide="[^"]+"', ''

        $classMatch = $classPattern.Match($attrs)
        if ($classMatch.Success) {
            $quote = $classMatch.Groups['q'].Value
            $existingClasses = $classMatch.Groups['classes'].Value
            $newClasses = ('bi ' + $biClass + ' ' + $existingClasses).Trim()
            $replacementClassAttr = 'class=' + $quote + $newClasses + $quote
            $attrs = $classPattern.Replace($attrs, $replacementClassAttr, 1)
        }
        else {
            $attrs = ($attrs + ' class="bi ' + $biClass + '"')
        }

        return '<i' + $attrs + '></i>'
    })

    if ($fileTagCount -gt 0 -and $newContent -ne $content) {
        Set-Content -Path $path -Value $newContent -NoNewline
        $updatedFiles++
        $updatedTags += $fileTagCount
    }
}

Write-Output "Updated files: $updatedFiles"
Write-Output "Updated icon tags: $updatedTags"
