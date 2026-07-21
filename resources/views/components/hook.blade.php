@props(['name'])

{!! app(\App\Support\Plugins\PluginManager::class)->renderHook($name, $attributes->getAttributes()) !!}
