<?php
namespace CortAIStudio\PageBuilder;

use CortAIStudio\Text;

defined('ABSPATH') || exit;

final class MediaSelection
{
    private array $used = [];
    public array $warnings = [];
    public array $assignments = [];

    public function __construct(private array $media, private bool $allow_reuse = false) {}

    public function choose(string $requested, string $subject, string $role): array
    {
        $tokens = array_unique(preg_split('/[^a-z0-9]+/', strtolower(remove_accents($subject . ' ' . $role)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $candidates = [];
        foreach ($this->media as $ref => $item) {
            $id = (int) ($item['image']['id'] ?? 0);
            $identities = [$id ? 'attachment:' . $id : 'reference:' . $ref];
            $url = $item['image']['url'] ?? '';
            if ($url !== '') {
                $path = preg_replace('/-(?:\d+x\d+|scaled)(?=\.[^.\/]+$)/', '', (string) wp_parse_url($url, PHP_URL_PATH));
                $identities[] = 'file:' . strtolower((string) wp_parse_url($url, PHP_URL_HOST)) . $path;
            }
            if (!$this->allow_reuse && array_intersect($identities, array_keys($this->used))) continue;
            $metadata = strtolower(remove_accents(($item['title'] ?? '') . ' ' . ($item['alt'] ?? '') . ' ' . ($item['description'] ?? '')));
            $score = 0;
            foreach ($tokens as $token) if (strlen($token) > 2 && str_contains($metadata, $token)) $score += 3;
            if ($ref === $requested) $score += 5;
            if ($role === 'hero' && ($item['width'] ?? 0) > ($item['height'] ?? 0) * 1.3) $score += 1;
            if (empty($item['automatic']) || $score > 1) $candidates[] = ['ref' => $ref, 'identities' => $identities, 'score' => $score, 'item' => $item];
        }
        usort($candidates, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['ref'], $b['ref']));
        $chosen = $candidates[0] ?? null;
        if ($chosen) {
            foreach ($chosen['identities'] as $identity) $this->used[$identity] = true;
            $image = $chosen['item']['image'];
            if ($requested !== '' && $chosen['ref'] !== $requested) $this->warnings[] = __('A duplicate or less relevant image reference was replaced with a distinct available image.', 'cort-ai-studio');
            if (!empty($image['id']) && $chosen['score'] < 3) $this->warnings[] = __('An image has limited matching metadata. Review its relevance in the preview.', 'cort-ai-studio');
            $pending = empty($image['id']);
            if ($pending) $image = $this->placeholder();
            $this->assignments[] = ['subject' => Text::plain($subject, 140), 'role' => $role, 'ref' => $chosen['ref'], 'id' => (int) ($image['id'] ?? 0), 'placeholder' => $pending];
            if ($pending) $this->warnings[] = __('An image is unavailable; an identifiable native placeholder is used.', 'cort-ai-studio');
            return $image + ['placeholder' => $pending];
        }
        $this->warnings[] = __('No distinct relevant image is available for one component. Add media or allow image generation.', 'cort-ai-studio');
        $this->assignments[] = ['subject' => Text::plain($subject, 140), 'role' => $role, 'ref' => '', 'id' => 0, 'placeholder' => true];
        return $this->placeholder() + ['placeholder' => true];
    }

    private function placeholder(): array
    {
        return ['id' => 0, 'url' => \Elementor\Utils::get_placeholder_image_src()];
    }
}
