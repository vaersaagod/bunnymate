# BunnyMate

Keeping your bunny finely-tuned for a hoppy life, mate!

<img src="https://github.com/vaersaagod/bunnymate/blob/main/src/icon.svg" width="200" height="200" alt="Logo">

## Description

BunnyMate integrates [BunnyCDN](https://bunny.net) with Craft CMS.

## Requirements

This plugin requires Craft CMS 4.0.0 or later.

## Disclaimer

This is a [private plugin](https://craftcms.com/docs/5.x/extend/plugin-guide.html#private-plugins), made for Værsågod and friends.

## Configuration

```
<?php

return [
    'pullingEnabled' => true,
    'pullZones' => [
        'default' => [
            'hostname' => 'https://awesome-project.b-cdn.net',
            'enabled' => true,
        ],
    ],
    'defaultPullZone' => 'default',
];
```

## Usage

BunnyMate provides a global Twig function `bunnyPullUrl()`, which can be used to generate a Bunny CDN pullzone URL:  

```
{% set bunnyUrl = bunnyPullUrl(asset) %}
{% set bunnyUrl = bunnyPullUrl(siteUrl('lorem/ipsim') %}
{% set bunnyUrl = bunnyPullUrl('lorem/ipsum') %}
{% set bunnyUrl = bunnyPullUrl('/lorem/ipsum', 'anotherZone') %}
```
