<?php namespace Anomaly\SearchModule\Search\Command;

use Anomaly\SearchModule\Search\SearchCriteria;
use Anomaly\SearchModule\Search\SearchItem;
use Anomaly\Streams\Platform\Addon\Module\Module;
use Anomaly\Streams\Platform\Addon\Module\ModuleCollection;
use Anomaly\Streams\Platform\Entry\Contract\EntryInterface;
use Anomaly\Streams\Platform\Support\Decorator;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Mmanos\Search\Query;
use Mmanos\Search\Search;

/**
 * Class GetSearchResults
 *
 * @link          http://anomaly.is/streams-platform
 * @author        AnomalyLabs, Inc. <hello@anomaly.is>
 * @author        Ryan Thompson <ryan@anomaly.is>
 * @package       Anomaly\SearchModule\Search\Command
 */
class GetSearchResults implements SelfHandling
{

    /**
     * The search criteria.
     *
     * @var SearchCriteria
     */
    protected $options;

    /**
     * Create a new GetSearchResults instance.
     *
     * @param SearchCriteria $criteria
     */
    public function __construct(SearchCriteria $criteria)
    {
        $this->criteria = $criteria;
    }

    public function handle(
        Decorator $decorator,
        Repository $config,
        Container $container,
        ModuleCollection $modules,
        Request $request,
        Search $search
    ) {
        /* @var Query $query */
        $query = $search->index($this->criteria->option('index', 'default'));

        $constraint = $this->criteria->option('in');

        if (!empty($constraint) && is_string($constraint)) {
            $query = $query->search('stream', $constraint, ['required' => true]);
        }

        if (!empty($constraint) && is_array($constraint)) {

            /* @var Module $module */
            foreach ($modules->withConfig('search') as $module) {
                foreach ($config->get($module->getNamespace('search')) as $model => $definition) {

                    /* @var EntryInterface $model */
                    $model = $container->make($model);

                    $stream = $model->getStreamNamespace() . '.' . $model->getStreamSlug();

                    if (!in_array($stream, $constraint)) {
                        $query = $query->search('stream', $stream, ['required' => false, 'prohibited' => true]);
                    }
                }
            }
        }

        foreach ($this->criteria->getOperations() as $operation) {
            $query = call_user_func_array([$query, $operation['name']], $operation['arguments']);
        }

        $page    = $request->get('page', 1);
        $perPage = $this->criteria->option('paginate', 15);

        $query->limit(
            $perPage,
            ($page - 1) * $perPage
        );

        $paginator = (new LengthAwarePaginator($query->get(), $query->count(), $perPage, $page))
            ->setPath($request->path())
            ->appends($request->all());

        foreach ($paginator->items() as &$result) {
            $result = $decorator->decorate(new SearchItem($result));
        }

        return $paginator;
    }
}