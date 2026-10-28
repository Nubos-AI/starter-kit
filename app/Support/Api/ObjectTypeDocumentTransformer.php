<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\ObjectType as ObjectTypeModel;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Illuminate\Support\Str;

class ObjectTypeDocumentTransformer implements DocumentTransformer
{
    private string $resourceSchema = 'JsonApiRecordResource';

    private string $discriminatorProperty = 'type';

    public function __construct(private ObjectTypeSchemaBuilder $builder) {}

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->secure(SecurityScheme::http('bearer'));

        $this->documentObjectTypes($document->components);
    }

    private function documentObjectTypes(Components $components): void
    {
        if (!$components->hasSchema($this->resourceSchema)) {
            return;
        }

        $mapping = [];
        $references = [];

        foreach ($this->objectTypes() as $type) {
            $name = $this->schemaName($components, $type->slug);

            $attributes = $components->addSchema(
                $name.__('i18n.backend.support.api.object_type_document_transformer.attributes'),
                Schema::fromType($this->builder->component($type)),
            );

            $reference = $components->addSchema(
                $name,
                Schema::fromType($this->resourceType($components, $type->slug, $attributes)),
            );

            $references[] = $reference;
            $mapping[$type->slug] = $this->referenceString($reference);
        }

        if ($mapping === []) {
            return;
        }

        $components->addSchema(
            $this->resourceSchema,
            Schema::fromType($this->polymorphicType($references, $mapping)),
        );
    }

    /**
     * @return iterable<int, ObjectTypeModel>
     */
    private function objectTypes(): iterable
    {
        return ObjectTypeModel::query()
            ->with('fieldDefinitions')
            ->orderBy('slug')
            ->get();
    }

    private function resourceType(Components $components, string $slug, Reference $attributes): ObjectType
    {
        $base = $components->getSchema($this->resourceSchema)->type;

        $type = $base instanceof ObjectType ? $base->clone() : new ObjectType;

        return $type
            ->addProperty($this->discriminatorProperty, (new StringType)->enum([$slug]))
            ->addProperty('attributes', $attributes)
            ->addRequired([$this->discriminatorProperty, 'attributes']);
    }

    private function schemaName(Components $components, string $slug): string
    {
        $name = Str::studly($slug).__('i18n.backend.support.api.object_type_document_transformer.record');

        return $components->hasSchema($name)
            ? Str::studly($slug).substr(md5($slug), 0, 6).__('i18n.backend.support.api.object_type_document_transformer.record')
            : $name;
    }

    private function referenceString(Reference $reference): string
    {
        /** @var array{'$ref': string} $array */
        $array = $reference->toArray();

        return $array['$ref'];
    }

    /**
     * @param  list<Reference>  $references
     * @param  array<string, string>  $mapping
     */
    private function polymorphicType(array $references, array $mapping): Type
    {
        return new class($references, $mapping, $this->discriminatorProperty) extends Type
        {
            /**
             * @param  list<Reference>  $references
             * @param  array<string, string>  $mapping
             */
            public function __construct(
                private array $references,
                private array $mapping,
                private string $propertyName,
            ) {
                parent::__construct('object');
            }

            /**
             * @return array<string, mixed>
             */
            public function toArray(): array
            {
                return [
                    'oneOf' => array_map(
                        static fn (Reference $reference): array => $reference->toArray(),
                        $this->references,
                    ),
                    'discriminator' => [
                        'propertyName' => $this->propertyName,
                        'mapping' => $this->mapping,
                    ],
                ];
            }
        };
    }
}
