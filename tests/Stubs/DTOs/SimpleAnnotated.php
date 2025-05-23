
declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

use Spatie\LaravelData\Data;

class SimpleAnnotated extends Data
{
    public function __construct(
        public string $name = 'John Doe',
        public ?string $description = null,
        public int $age = 30,
        public bool $active = true,
    ) {
    }
}
declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
#[Parameter(name: 'first_name', type: 'string', format: 'email', description: 'The first name')]
#[Parameter(name: 'age', type: 'integer')]
class SimpleAnnotated extends Data
{
    public function __construct(
        public string $firstName,
        public int $age,
    ) {}
}
