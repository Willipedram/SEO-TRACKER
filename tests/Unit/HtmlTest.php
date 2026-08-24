<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Html;
use Tests\TestCase;

final class HtmlTest extends TestCase
{
    public function testUntrustedTextIsEscaped(): void
    {
        $this->assertSame('&lt;script&gt;&quot;&amp;&quot;&lt;/script&gt;', Html::escape('<script>"&"</script>'));
    }
}
