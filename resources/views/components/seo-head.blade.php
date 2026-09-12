@props([
    'page' => 'home',
    'title' => null,
    'description' => null,
])

@php
    $seo = \App\Support\SiteSettings::seoForPage($page);
    $siteName = \App\Support\SiteSettings::get('general', 'site_name', config('app.name', 'BizFlow'));
    $pageTitle = $title ?? $seo['title'] ?? config('app.name');
    $fullTitle = $page === 'home' 
        ? "{$pageTitle} — Effortless Booking & Diary"
        : "{$pageTitle} — {$siteName}";

    $pageDesc = $description ?? $seo['description'] ?? 'Simple booking, diary and reminder software for UK salons, barbers and local service businesses.';
    $canonicalBase = rtrim($seo['canonical_base'] ?? url('/'), '/');
    $pagePath = $page === 'home' ? '' : "/{$page}";
    $canonicalUrl = "{$canonicalBase}{$pagePath}";
    $robots = $seo['robots'] ?? 'index, follow';
    $keywords = $seo['keywords'] ?? '';
    $twitterHandle = $seo['twitter_handle'] ?? '@bizflowapp';
    $googleVerification = $seo['google_verification'] ?? '';
@endphp

<title>{{ $fullTitle }}</title>
<meta name="description" content="{{ $pageDesc }}">
@if (!empty($keywords))
    <meta name="keywords" content="{{ $keywords }}">
@endif
<meta name="robots" content="{{ $robots }}">
<link rel="canonical" href="{{ $canonicalUrl }}">

{{-- Open Graph / Facebook --}}
<meta property="og:type" content="website">
<meta property="og:url" content="{{ $canonicalUrl }}">
<meta property="og:title" content="{{ $fullTitle }}">
<meta property="og:description" content="{{ $pageDesc }}">
<meta property="og:site_name" content="{{ $siteName }}">

{{-- Twitter Cards --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $fullTitle }}">
<meta name="twitter:description" content="{{ $pageDesc }}">
@if (!empty($twitterHandle))
    <meta name="twitter:site" content="{{ $twitterHandle }}">
    <meta name="twitter:creator" content="{{ $twitterHandle }}">
@endif

{{-- Search Console Verification --}}
@if (!empty($googleVerification))
    <meta name="google-site-verification" content="{{ $googleVerification }}">
@endif
