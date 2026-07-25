<?php
declare(strict_types=1);

$logoVariant = $logoVariant ?? 'default';
$logoClass = trim('brand-logo brand-logo--' . $logoVariant . ' ' . ($logoClass ?? ''));
$logoSrc = $logoVariant === 'compact'
    ? asset_url('Images/favicon.png')
    : asset_url('Images/academic-portfolio-logo.png');
$logoWidth = (int) ($logoWidth ?? ($logoVariant === 'compact' ? 36 : 180));
$logoHeight = (int) ($logoHeight ?? ($logoVariant === 'compact' ? 36 : 48));
$showBrandText = $showBrandText ?? ($logoVariant !== 'compact');
?>
<span class="<?= e($logoClass); ?>">
    <img
        src="<?= e($logoSrc); ?>"
        alt="Academic Portfolio"
        width="<?= $logoWidth; ?>"
        height="<?= $logoHeight; ?>"
        decoding="async"
    >
    <?php if ($showBrandText): ?>
        <span class="brand-logo-text">Portafolio Académico Inteligente</span>
    <?php endif; ?>
</span>
