{{--
    CMS Layout Wrapper Component

    Renders content with appropriate layout based on PageLayout enum.
    Handles all 4 layout types: default, full-width, minimal, home.

    @props
    - layout: PageLayout enum instance (required)
    - model: Page|Post|Promotion model instance (required)
    - type: 'page'|'post'|'promotion' (required)
    - relatedPosts: Collection of related posts (for post sidebar)
    - recentPosts: Collection of recent posts (for page/promotion sidebar)
--}}

@props([
    'layout',
    'model',
    'type',
    'relatedPosts' => null,
    'recentPosts' => null,
])

@php
use App\Enums\PageLayout;

// Ensure layout is PageLayout enum
if (is_string($layout)) {
    $layout = PageLayout::from($layout);
}
@endphp

{{-- Homepage Special Layout (no wrapper) --}}
@if($layout->isHomepage())
    <x-cms.layouts.home :model="$model" />

{{-- Default Layout (8+4 grid with sidebar) --}}
@elseif($layout === PageLayout::DEFAULT)
    <x-cms.layouts.default :model="$model" :type="$type" :relatedPosts="$relatedPosts" :recentPosts="$recentPosts" />

{{-- Full-Width Layout (edge-to-edge) --}}
@elseif($layout === PageLayout::FULL_WIDTH)
    <x-cms.layouts.full-width :model="$model" :type="$type" />

{{-- Minimal Layout (narrow reading column) --}}
@elseif($layout === PageLayout::MINIMAL)
    <x-cms.layouts.minimal :model="$model" :type="$type" />

@endif
