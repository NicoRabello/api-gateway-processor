<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_readme_documents_composite_deduplication_key(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertStringContainsString('source_file_hash', $readme);
        $this->assertStringContainsString('line_number', $readme);
        $this->assertStringContainsString('payload_hash', $readme);
        $this->assertStringNotContainsString('`payload_hash` unico', $readme);
    }
}
