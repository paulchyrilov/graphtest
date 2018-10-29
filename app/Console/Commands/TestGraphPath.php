<?php
namespace App\Console\Commands\Graph;

use Fhaculty\Graph\Edge\Directed;
use Fhaculty\Graph\Graph;
use Fhaculty\Graph\Vertex;
use Fhaculty\Graph\Walk;
use Graphp\Algorithms\ShortestPath\Dijkstra;
use Illuminate\Console\Command;

use Illuminate\Support\Collection;

/**
 * Class TestGraphPath
 *
 * @package App\Console\Commands\Graph
 */
class TestGraphPath extends Command
{
    /**
     * found route message printf template
     */
    protected const FOUND_ROUTE_MESSAGE = '<fg=green>%s %s (%d) FOUND: %s via %s with costs %s (%d)</>';

    /**
     * total found message template
     */
    protected const TOTAL_FOUND_MESSAGE = 'Found %d shortest routes';

    /**
     * @var string
     */
    protected $signature = 'graph:test {--verbose:show result routes}';

    /**
     * @var string
     */
    protected $description = 'Search alternative paths for top direct flights';

    /**
     *  handle command
     */
    public function handle(): void
    {
        $this->output->title('Shortest alternative paths');

        try {
            $csv = file_get_contents('storage/graph/testDirections.csv');
            $lines = explode("\n", $csv);
            $testDirections = [];
            foreach ($lines as $line) {
                $values = explode(',', $line);
                if(4 !== \count($values)) {
                    continue;
                }
                $trip = new \stdClass();
                $trip->departure = $values[0];
                $trip->arrival = $values[1];
                $trip->weight  = $values[2];
                $trip->total_count  = $values[3];
                $testDirections[] = $trip;
            }

            $csv = file_get_contents('storage/graph/trips.csv');
            $lines = explode("\n", $csv);
            $trips = [];
            foreach ($lines as $line) {
                $values = explode(',', $line);
                if(6 !== \count($values)) {
                    continue;
                }
                $trip = new \stdClass();
                $trip->departure = $values[0];
                $trip->arrival = $values[1];
                $trip->weight  = $values[2];
                $trip->provider  = $values[3];
                $trip->segments_count  = $values[4];
                $trip->carrier  = $values[5];
                $trips[] = $trip;
            }
            $trips = new Collection($trips);
            $this->info('Trips:' . \count($trips));

            $startTime = \microtime(true);
            $graph = $this->createGraph($trips);
            $this->info('Vertices: ' . $graph->getVertices()->count());
            $this->info('Edges: ' . $graph->getEdges()->count() . "\n");

            $fountWalks = [];
            foreach ($testDirections as $direction) {
                $walk = $this->search($direction->departure, $direction->arrival, $graph);

                if ($walk) {
                    $fountWalks[] = [
                        'direction' => $direction,
                        'walk' => $walk
                    ];
                }
            }
            $endTime = \microtime(true);

            $this->info(\sprintf('Processing time: %s seconds', $endTime - $startTime));
            $this->line('');

            if($this->option('verbose')) {
                foreach ($fountWalks as $data) {
                    /** @var Walk $walk */
                    $walk = $data['walk'];
                    $direction = $data['direction'];

                    $minWeight = 0;
                    $routes = [
                        $direction->departure
                    ];
                    $providers = [];
                    $weights = [];
                    foreach ($walk->getEdges() as $edge) {
                        /** @var Directed $edge */
                        $minWeight += $edge->getWeight();
                        $routes[] = $edge->getVertexEnd()->getId();
                        $providers[] = $edge->getAttribute('provider');
                        $carriers[] = $edge->getAttribute('carrier');
                        $weights[] = $edge->getWeight();
                    }
                    $this->output->writeln(
                        \sprintf(
                            self::FOUND_ROUTE_MESSAGE,
                            $direction->departure,
                            $direction->arrival,
                            $direction->weight,
                            \implode(' -> ', $routes),
                            \implode(' -> ', $providers),
                            \implode(' -> ', $weights),
                            $minWeight
                        )
                    );
                }
            }

            $this->output->success(\sprintf(self::TOTAL_FOUND_MESSAGE, \count($fountWalks)));
        } catch (\Throwable $e) {
            try {
                $this->output->error($e->getMessage());
            } catch (\Throwable $e) {
                $this->output->error($e->getMessage());
            }
        }
    }

    /**
     * @param string $departure
     * @param string $arrival
     * @param Graph $graph
     * @return Walk|null
     */
    public function search(
        string $departure,
        string $arrival,
        Graph $graph
    ): ?Walk {
        if ($graph->hasVertex($departure) && $graph->hasVertex($arrival)) {
            $fromVertex = $graph->getVertex($departure);
            $toVertex = $graph->getVertex($arrival);

            $algoFrom = new Dijkstra($fromVertex);

            return $algoFrom->getWalkTo($toVertex);
        }

        return null;
    }

    /**
     * @param Collection $trips
     * @return Graph
     */
    protected function createGraph(Collection $trips): Graph
    {
        $graph = new Graph();

        foreach ($trips as $trip) {
            $graph = $this->addConnection(
                $graph,
                $this->aglomerations[$trip->departure] ?? $trip->departure,
                $this->aglomerations[$trip->arrival] ?? $trip->arrival,
                $trip->provider,
                $trip->carrier,
                $trip->segments_count,
                (int)$trip->weight
            );
        }

        return $graph;
    }

    /**
     * @param Graph $graph
     * @param string $departure
     * @param string $arrival
     * @param string $provider
     * @param string $carrier
     * @param int|null $segments
     * @param int $weight
     * @return Graph
     */
    protected function addConnection(
        Graph $graph,
        string $departure,
        string $arrival,
        string $provider,
        ?string $carrier,
        ?int $segments,
        int $weight
    ): Graph {
        if ($graph->hasVertex($departure)) {
            $departureVertex = $graph->getVertex($departure);
        } else {
            $departureVertex = $graph->createVertex($departure);
        }
        if ($graph->hasVertex($arrival)) {
            $arrivalVertex = $graph->getVertex($arrival);
        } else {
            $arrivalVertex = $graph->createVertex($arrival);
        }

        $newEdge = $departureVertex->createEdgeTo($arrivalVertex);
        $newEdge->setWeight($weight);
        $newEdge->setAttribute('provider', $provider);
        $newEdge->setAttribute('carrier', $carrier);
        $newEdge->setAttribute('segments', (int)$segments);

        return $graph;
    }
}
