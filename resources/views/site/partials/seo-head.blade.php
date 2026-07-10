@php
    $seoTitle = trim((string) ($pageTitle ?? $siteName ?? $siteTitle ?? config('app.name')));
    $seoDescription = trim((string) ($pageDescription ?? ''));
    $seoKeywords = trim((string) ($pageKeywords ?? $siteKeywords ?? ''));
    $seoCanonicalUrl = trim((string) ($canonicalUrl ?? url()->current()));
    $seoOgType = trim((string) ($pageOgType ?? (isset($article) ? 'article' : 'website')));
    $seoSiteName = trim((string) ($siteName ?? $siteTitle ?? config('app.name')));
    $seoImage = trim((string) ($pageImage ?? ''));
@endphp
<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
@if($seoKeywords !== '')
    <meta name="keywords" content="{{ $seoKeywords }}">
@endif
@if(!empty($siteFavicon))
    <link rel="icon" href="{{ $siteFavicon }}">
@endif
<link rel="canonical" href="{{ $seoCanonicalUrl }}">
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:type" content="{{ $seoOgType }}">
<meta property="og:url" content="{{ $seoCanonicalUrl }}">
<meta property="og:site_name" content="{{ $seoSiteName }}">
@if($seoImage !== '')
    <meta property="og:image" content="{{ $seoImage }}">
@endif
