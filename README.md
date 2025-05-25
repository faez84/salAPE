
# Integrating Elasticsearch with Symfony API Platform

If you're building an API using Symfony and API Platform and need advanced search and filtering, Elasticsearch is a powerful option. In this guide, we walk through integrating Elasticsearch in a Symfony 6 + API Platform application.

## Step 1: Define Your Entity

```php
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: 'productselastic',
            provider: ElasticsearchProductProvider::class,
            stateOptions: new Options(index: 'productelastic')
        ),
        new Post(),
        new Put(),
        new Patch(),
        new Delete()
    ],
    normalizationContext: ['groups' => ['productselastic:read']],
)]
#[ApiFilter(MatchFilter::class, properties: ['title'])]
class ProductElastic
{
    #[ApiProperty(identifier: true)]
    #[Groups(['productselastic:read'])]
    public ?int $id = null;

    #[Groups(['productselastic:read'])]
    private ?string $title = null;

    #[Groups(['productselastic:read'])]
    private ?float $price = null;

    #[Groups(['productselastic:read'])]
    private ?int $quantity = null;

    #[Groups(['productselastic:read'])]
    private ?string $description = null;

    #[Groups(['productselastic:read'])]
    private ?string $artNum = null;

    #[Groups(['productselastic:read'])]
    private ?string $features = null;
}
```

## Step 2: Index Your Data

```php
class ProductElasticCommand extends Command
{
    public $client;
    public function __construct(
        private EntityManagerInterface $em,
 
    ) {
        parent::__construct();
        $this->client = ClientBuilder::create()->setHosts(['http://elasticsearch:9200'])->build();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->client->indices()->create([
    'index' => 'productelastic',
    'body' => [
        'mappings' => [
            'properties' => [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'text'],
                'price' => ['type' => 'float'],
                'quantity' => ['type' => 'integer'],
                'description' => ['type' => 'text'],
                'image' => ['type' => 'text'],
                'artNum' => ['type' => 'keyword'],
                'features' => ['type' => 'text'],
                'category' => ['type' => 'keyword']
            ]
        ]
    ]
]);

        $products = $this->em->getRepository(Product::class)->findAll();

        foreach ($products as $product) {
            $this->client->index([
                'index' => 'productelastic',
                'id' => $product->getId(),
                'body' => [
                    'id' => $product->getId(),
                    'title' => $product->getTitle(),
                    'price' => $product->getPrice(),
                    'quantity' => $product->getQuantity(),
                    'description' => $product->getDescription(),
                    'image' => $product->getImage(),
                    'artNum' => $product->getArtNum(),
                    'features' => $product->getFeatures(),
                    'category' => '/api/categories/' . $product->getCategory()?->getId(),
                ],
            ]);
        }

        $output->writeln('Books indexed successfully.');
        return Command::SUCCESS;
    }
}
```
## Step 3: Implement a paginator class that implements PaginatorInterface

```php
class ElasticsearchPaginator implements \IteratorAggregate, PaginatorInterface
{
    private array $items;
    private int $currentPage;
    private int $itemsPerPage;
    private int $totalItems;

    public function __construct(array $items = [], int $currentPage = 1, int $itemsPerPage = 10, int $totalItems = 100)
    {
        $this->items = $items;
        $this->currentPage = $currentPage;
        $this->itemsPerPage = $itemsPerPage;
        $this->totalItems = $totalItems;
    }

    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    public function getCurrentPage(): float
    {
        return (float) $this->currentPage;
    }

    public function getItemsPerPage(): float
    {
        return (float) $this->itemsPerPage;
    }

    public function getTotalItems(): float
    {
        return (float) $this->totalItems;
    }

    function count(): int
    {
        return $this->totalItems;
    }

    public function getLastPage(): float
    {
        return (int) ceil($this->totalItems / $this->itemsPerPage);
    }
}

```

## Step 4: Create a Custom Elasticsearch Provider

```php
class ElasticsearchProductProvider implements ProviderInterface
{
    public $client;
    public function __construct(
        private DenormalizerInterface $denormalizer,
        private Pagination $pagination
    ) {
        $this->client = ClientBuilder::create()->setHosts(['http://elasticsearch:9200'])->build();
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $filters = $context['filters'] ?? [];
    // Get pagination parameters from context
    $page = $this->pagination->getPage($context);
    $itemsPerPage = $this->pagination->getLimit($operation);

    $from = ($page - 1) * $itemsPerPage;
        $must = [];
        $should = [];

        // Full-text search if `search` param is provided
        if (isset($filters['search'])) {
            $should[] = [
                'multi_match' => [
                    'query' => $filters['search'],
                    'fields' => [
                        'title^3',
                        'description',
                        'features',
                        'image',
                        'artNum',
                        'category',
                    ],
                    'type' => 'best_fields'
                ]
            ];
        }
    
        // Exact field filters
        foreach (['title', 'artNum', 'price', 'quantity', 'features'] as $field) {
            if (!empty($filters[$field])) {
                $must[] = [
                    'match' => [
                        $field => $filters[$field],
                    ]
                ];
            }
        }
    
        // Final query
        $query = [];
        if ($should) {
            $query['bool']['should'] = $should;
            $query['bool']['minimum_should_match'] = 1;
        }
        if ($must) {
            $query['bool']['must'] = array_merge($query['bool']['must'] ?? [], $must);
        }
        if (!$must && !$should) {
            $query = ['match_all' => (object)[]];
        }
    
        $response = $this->client->search([
            'index' => 'productelastic',
            'body' => [
                'from' => $from,
                'size' => $itemsPerPage,
                'query' => $query
            ]
        ]);
        $items = array_map(fn ($hit) => $this->denormalizer->denormalize($hit['_source'], ProductElastic::class), $response['hits']['hits']);
        $total = $response['hits']['total']['value'] ?? count($items);
    
        return new ElasticsearchPaginator($items, $page, $itemsPerPage, $total);
    }
}
```

## Step 5: Use and Test Your API

With this setup, you can now access:
- `GET /productselastic` with pagination using `?page=2` and `?itemsPerPage=5`
- Filtering with `?title=value` or `?search=query`

## Conclusion

This approach uses a custom provider, allowing you full control over how data is queried and returned from Elasticsearch. API Platform’s extensibility makes integrating external sources seamless.

### Github repository 
https://github.com/faez84/salAPE

### Github 
https://github.com/faez84