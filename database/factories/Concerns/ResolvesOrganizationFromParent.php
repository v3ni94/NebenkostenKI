<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Leitet die Mandantenspalte aus dem bereits erzeugten Elterndatensatz ab.
 *
 * Dadurch bleiben organization_id und die Fremdschluessel in Testdaten immer
 * konsistent. Die Reihenfolge in definition() ist relevant: der Fremdschluessel
 * muss vor der Mandantenspalte stehen.
 */
trait ResolvesOrganizationFromParent
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  class-string<Model>  $parentModel
     */
    protected function organizationFrom(array $attributes, string $parentModel, string $foreignKey): string
    {
        $parentId = $attributes[$foreignKey] ?? null;

        if ($parentId === null) {
            return '';
        }

        $value = $parentModel::query()->whereKey($parentId)->value('organization_id');

        return is_string($value) ? $value : '';
    }
}
