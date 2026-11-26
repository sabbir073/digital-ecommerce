<?php

namespace App\Services\Seo;

use Illuminate\Database\Eloquent\Model;

/** The checks shown in every SEO panel and collected on the SEO dashboard. */
class SeoChecklist
{
    public const TITLE_MAX = 60;

    public const DESCRIPTION_MAX = 160;

    public const THIN_WORDS = 50;

    public function __construct(private readonly SeoSubjects $subjects, private readonly SeoBuilder $builder) {}

    /**
     * @param  array<string, mixed>|null  $meta  unsaved fields from the panel; the saved ones when null
     * @return list<array{key:string, ok:bool, label:string, hint:string}>
     */
    public function run(string $type, ?Model $model, ?array $meta = null): array
    {
        $meta ??= $this->subjects->meta($type, $model);
        $facts = $this->subjects->facts($type, $model);
        $resolved = $this->builder->resolve($type, $meta, $facts);
        $titleLength = mb_strlen($resolved['title']);
        $descriptionLength = mb_strlen($resolved['description']);
        $checks = [];

        $checks[] = [
            'key' => 'title',
            'ok' => $titleLength > 0 && $titleLength <= self::TITLE_MAX,
            'label' => 'Meta title',
            'hint' => $titleLength === 0 ? 'There is no title. Write one, or set a title template under SEO, Settings.'
                : ($titleLength > self::TITLE_MAX ? "The title is {$titleLength} characters. Search results cut it off after about ".self::TITLE_MAX.'.' : "{$titleLength} characters."),
        ];
        $checks[] = [
            'key' => 'description',
            'ok' => $descriptionLength > 0 && $descriptionLength <= self::DESCRIPTION_MAX,
            'label' => 'Meta description',
            'hint' => $descriptionLength === 0 ? 'There is no description. Write one or two sentences that say what the page offers.'
                : ($descriptionLength > self::DESCRIPTION_MAX ? "The description is {$descriptionLength} characters. Search results cut it off after about ".self::DESCRIPTION_MAX.'.' : "{$descriptionLength} characters."),
        ];
        $checks[] = [
            'key' => 'h1',
            'ok' => $facts['has_h1'],
            'label' => 'Main heading (H1)',
            'hint' => $facts['has_h1'] ? 'The page has one main heading.' : 'The page has no main heading. Add a Hero block: its headline is the main heading.',
        ];
        $checks[] = [
            'key' => 'alt',
            'ok' => $facts['missing_alt'] === 0,
            'label' => 'Image alt text',
            'hint' => $facts['missing_alt'] === 0 ? 'Every image has alt text.'
                : $facts['missing_alt'].' '.($facts['missing_alt'] === 1 ? 'image has' : 'images have').' no alt text. Add it in the Media library.',
        ];
        if (in_array($type, ['product', 'selling_page', 'page', 'post'], true)) {
            $words = str_word_count($facts['text']);
            $checks[] = [
                'key' => 'content',
                'ok' => $words >= self::THIN_WORDS,
                'label' => 'Enough content',
                'hint' => $words >= self::THIN_WORDS ? "About {$words} words." : "Only about {$words} words. Pages with fewer than ".self::THIN_WORDS.' rarely rank; add more detail.',
            ];
        }
        $keyword = mb_strtolower(trim((string) $meta['focus_keyword']));
        if ($keyword !== '') {
            $inTitle = str_contains(mb_strtolower($resolved['title']), $keyword);
            $inDescription = str_contains(mb_strtolower($resolved['description']), $keyword);
            $checks[] = [
                'key' => 'keyword',
                'ok' => $inTitle && $inDescription,
                'label' => 'Focus keyword',
                'hint' => $inTitle && $inDescription ? 'The keyword is in the title and the description.'
                    : 'The keyword is missing from the '.implode(' and the ', array_keys(array_filter(['title' => ! $inTitle, 'description' => ! $inDescription]))).'.',
            ];
        }

        return $checks;
    }
}
