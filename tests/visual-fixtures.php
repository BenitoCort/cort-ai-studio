<?php
namespace CortAIStudioTests;

/** Cafe content is a test scenario, never a production template. */
function cafe_plan(string $imageRef): array
{
    $heading = static fn(string $text, string $tag = 'h2', string $role = 'title'): array => array_replace(widget('heading', $text), ['heading_tag' => $tag, 'role' => $role]);
    $photo = static fn(string $subject): array => array_replace(widget('image', $subject), ['media_ref' => $imageRef, 'image_subject' => $subject, 'image_treatment' => 'landscape', 'hover' => 'zoom']);
    $button = static fn(string $text, string $anchor): array => array_replace(widget('button', $text), ['link' => '#' . $anchor, 'role' => 'primary_button']);
    $column = static fn(array $widgets, string $surface = 'none', string $align = 'left'): array => ['widgets' => $widgets, 'surface' => $surface, 'align' => $align, 'width' => 0];
    $section = static fn(string $name, string $pattern, array $columns, string $title = '', string $tone = 'light', string $anchor = ''): array => ['label' => $name, 'pattern' => $pattern, 'tone' => $tone, 'title' => $title, 'eyebrow' => '', 'intro' => '', 'heading_align' => 'left', 'anchor' => $anchor, 'background' => '', 'padding' => 32, 'gap' => 24, 'columns' => $columns];
    $plan = plan();
    $plan['summary'] = 'Five distinct, native editable cafe sections.';
    $plan['sections'] = [
        $section('Hero', 'split', [
            $column([$heading('Specialty coffee. A slower moment.', 'h1', 'display'), widget('text-editor', 'Thoughtfully prepared drinks, fresh pastries and a welcoming space.'), $button('Explore the menu', 'menu')]),
            $column([$photo('cafe atmosphere interior warm light')]),
        ]),
        $section('About', 'content', [
            $column([$photo('barista preparing artisan coffee')]),
            $column([$heading('Made with care'), widget('text-editor', 'A place for the morning ritual, a conversation and a well-made cup.')]),
        ], '', 'light', 'about'),
        $section('Featured Products', 'cards', [
            $column([$photo('signature latte coffee cup'), $heading('Signature Latte', 'h3', 'card_title'), widget('text-editor', 'Smooth espresso with carefully textured milk.')], 'card'),
            $column([$photo('cold brew iced drink'), $heading('Cold Brew', 'h3', 'card_title'), widget('text-editor', 'Slow-steeped coffee served chilled.')], 'card'),
            $column([$photo('fresh pastries bakery croissant'), $heading('Fresh Pastries', 'h3', 'card_title'), widget('text-editor', 'A fresh accompaniment to your favorite drink.')], 'card'),
        ], 'Find your favorite', 'light', 'menu'),
        $section('Why Choose Us', 'features', [
            $column([array_replace(widget('icon'), ['icon' => 'coffee']), $heading('Care in every cup', 'h3', 'card_title'), widget('text-editor', 'Attention from preparation to serving.')], 'card'),
            $column([array_replace(widget('icon'), ['icon' => 'leaf']), $heading('Simple ingredients', 'h3', 'card_title'), widget('text-editor', 'Coffee and fresh flavors at the center.')], 'card'),
            $column([array_replace(widget('icon'), ['icon' => 'heart']), $heading('A welcoming space', 'h3', 'card_title'), widget('text-editor', 'Settle in and enjoy the moment.')], 'card'),
        ], 'A little more than coffee', 'dark'),
        $section('Final CTA', 'cta', [$column([widget('text-editor', 'Make time for a good cup.'), $button('Choose your next coffee', 'menu')], 'none', 'center')], 'Your next favorite moment', 'accent'),
    ];
    $plan['sections'][4]['heading_align'] = 'center';
    return $plan;
}

function cafe_direction(): array
{
    $plan = direction(5);
    $plan['sections'][3]['tone'] = 'dark';
    $plan['sections'][4]['tone'] = 'accent';
    return $plan;
}
