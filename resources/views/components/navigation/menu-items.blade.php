@props([
    'location' => 'header',
    'dark' => false,
])

@inject('navigation', 'App\Services\NavigationService')

@foreach($navigation->getMenuItems($location) as $item)
    <x-ios.nav-item
        :href="$item['url']"
        :label="$item['label']"
        :active="$item['active']"
        :dark="$dark"
    />
@endforeach
