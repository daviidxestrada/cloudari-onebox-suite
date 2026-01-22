<?php
namespace Cloudari\Onebox\Domain\MainProfile;

final class MainProfile
{
    public string $slug;
    public string $label;
    public string $theatreName;
    public string $colorPrimary;
    public string $colorAccent;
    public string $colorBackground;
    public string $colorText;
    public string $colorSelectedDay;

    public function __construct(
        string $slug,
        string $label,
        string $theatreName,
        string $colorPrimary,
        string $colorAccent,
        string $colorBackground,
        string $colorText,
        string $colorSelectedDay
    ) {
        $this->slug = $slug;
        $this->label = $label;
        $this->theatreName = $theatreName;
        $this->colorPrimary = $colorPrimary;
        $this->colorAccent = $colorAccent;
        $this->colorBackground = $colorBackground;
        $this->colorText = $colorText;
        $this->colorSelectedDay = $colorSelectedDay;
    }

    public static function fromArray(array $data): self
    {
        $defaultPrimary = $data['color_primary'] ?? '#009AD8';

        return new self(
            $data['slug'] ?? 'default',
            $data['label'] ?? 'Perfil principal',
            $data['theatre_name'] ?? 'Teatro',
            $defaultPrimary,
            $data['color_accent'] ?? '#D14100',
            $data['color_bg'] ?? '#FFFFFF',
            $data['color_text'] ?? '#000000',
            $data['color_selected_day'] ?? $defaultPrimary
        );
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'theatre_name' => $this->theatreName,
            'color_primary' => $this->colorPrimary,
            'color_accent' => $this->colorAccent,
            'color_bg' => $this->colorBackground,
            'color_text' => $this->colorText,
            'color_selected_day' => $this->colorSelectedDay,
        ];
    }
}
