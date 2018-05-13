<?php

namespace Foolz\SphinxQL;

use Foolz\SphinxQL\Drivers\ConnectionInterface;
use Foolz\SphinxQL\Exception\SphinxQLException;

/**
 * Query Builder class for Percolate Queries.
 *
 * ### INSERT ###
 *
 * $query = (new Percolate($conn))
 *    ->insert('full text query terms', noEscape = false)       // Allowed only one insert per query (Symbol @ indicates field in sphinx.conf)
 *                                                                 No escape tag cancels characters shielding (default on)
 *    ->into('pq')                                              // Index for insert
 *    ->tags(['tag1','tag2'])                                   // Adding tags. Can be array ['tag1','tag2'] or string delimited by coma
 *    ->filter('price>3')                                       // Adding filter (Allowed only one)
 *    ->execute();
 *
 *
 * ### CALL PQ ###
 *
 *
 * $query = (new Percolate($conn))
 *    ->callPQ()
 *    ->from('pq')                                              // Index for call pq
 *    ->documents(['multiple documents', 'go this way'])        // see getDocuments function
 *    ->options([                                               // See https://docs.manticoresearch.com/latest/html/searching/percolate_query.html#call-pq
 *          Percolate::OPTION_VERBOSE => 1,
 *          Percolate::OPTION_DOC_JSON => 1
 *    ])
 *    ->execute();
 *
 *
 */
class Percolate
{

    /**
     * @var ConnectionInterface
     */
    protected $connection;

    /**
     * Documents for CALL PQ
     *
     * @var array
     */
    protected $documents;

    /**
     * Index name
     *
     * @var string
     */
    protected $index;

    /**
     * Insert query
     *
     * @var string
     */
    protected $query;

    /**
     * Options for CALL PQ
     * @var array
     */
    protected $options = [self::OPTION_DOC_JSON => 1];

    /**
     * @var array
     */
    protected $filters = [];

    /**
     * Query type (call | insert)
     *
     * @var string
     */
    protected $type = 'call';

    /** INSERT STATEMENT  **/

    protected $tags = [];

    /**
     * @var array
     */
    protected $escapeChars = [
        '\\' => '\\\\',
        '-' => '\-',
        '~' => '\~',
        '<' => '\<',
        '"' => '\"',
        "'" => "\'",
        '/' => '\/'
    ];

    /** @var SphinxQL */
    protected $sphinxQL;

    /**
     * CALL PQ option constants
     */
    const OPTION_DOC_JSON = 'as docs_json';
    const OPTION_DOCS = 'as docs';
    const OPTION_VERBOSE = 'as verbose';
    const OPTION_QUERY = 'as query';

    /**
     * @param ConnectionInterface $connection
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
        $this->sphinxQL = new SphinxQL($this->connection);
        $this->escapeChars = $this->sphinxQL->compileEscapeChars($this->escapeChars);
    }


    /**
     * Clear all fields after execute
     */
    private function clear()
    {
        $this->documents = null;
        $this->index = null;
        $this->query = null;
        $this->options = [self::OPTION_DOC_JSON => 1];
        $this->type = 'call';
        $this->filters = [];
        $this->tags = [];
    }

    /**
     * Analog into function
     * Sets index name for query
     *
     * @param string $index
     *
     * @return $this
     * @throws SphinxQLException
     */
    public function from($index)
    {
        if (empty($index)) {
            throw new SphinxQLException('Index can\'t be empty');
        }

        $this->index = trim($index);
        return $this;
    }

    /**
     * Analog from function
     * Sets index name for query
     *
     * @param string $index
     *
     * @return $this
     * @throws SphinxQLException
     */
    public function into($index)
    {
        if (empty($index)) {
            throw new SphinxQLException('Index can\'t be empty');
        }
        $this->index = trim($index);
        return $this;
    }

    /**
     * Replacing bad chars
     *
     * @param string $query
     *
     * @return string mixed
     */
    protected function escapeString($query)
    {
        return str_replace(
            array_keys($this->escapeChars),
            array_values($this->escapeChars),
            $query);
    }

    /**
     * Adding tags for insert query
     *
     * @param array|string $tags
     *
     * @return $this
     */
    public function tags($tags)
    {
        if (is_array($tags)) {
            $tags = array_map([$this, 'escapeString'], $tags);
            $tags = implode(',', $tags);
        } else {
            $tags = $this->escapeString($tags);
        }
        $this->tags = $tags;
        return $this;
    }

    /**
     * Add filter for insert query
     *
     * @param string $filter
     * @return $this
     *
     * @throws SphinxQLException
     */
    public function filter($filter)
    {
        $filters = explode(',', $filter);
        if (!empty($filters[1])) {
            throw new SphinxQLException(
                'Allow only one filter. If there is a comma in the text, it must be shielded');
        }
        $this->filters = $filter;
        return $this;
    }

    /**
     * Add insert query
     *
     * @param string $query
     * @param bool $noEscape
     *
     * @return $this
     */
    public function insert($query, $noEscape = false)
    {
        if (!$noEscape) {
            $query = $this->escapeString($query);
        }
        $this->query = $query;
        $this->type = 'insert';
        return $this;
    }

    /**
     * Generate array for insert, from setted class parameters
     *
     * @return array
     */
    private function generateInsert()
    {
        $insertArray = ['query' => $this->query];

        if (!empty($this->tags)) {
            $insertArray['tags'] = $this->tags;
        }

        if (!empty($this->filters)) {
            $insertArray['filters'] = $this->filters;
        }

        return $insertArray;
    }

    /**
     * Executs query and clear class parameters
     *
     * @return Drivers\ResultSetInterface
     * @throws SphinxQLException
     */
    public function execute()
    {

        if ($this->type == 'insert') {
            $result = $this->sphinxQL
                ->insert()
                ->into($this->index)
                ->set($this->generateInsert());
        } else {
            $result = $this->sphinxQL
                ->query("CALL PQ ('" .
                    $this->index . "', " . $this->getDocuments() . " " . $this->getOptions() . ")");
        }

        $this->clear();
        return $result->execute();
    }

    /**
     * Set one option for CALL PQ
     *
     * @param string $key
     * @param int $value
     *
     * @return $this
     * @throws SphinxQLException
     */
    private function setOption($key, $value)
    {
        $value = intval($value);
        if (!in_array($key, [
            self::OPTION_DOC_JSON,
            self::OPTION_DOCS,
            self::OPTION_VERBOSE,
            self::OPTION_QUERY
        ])) {
            throw new SphinxQLException('Unknown option');
        }

        if ($value != 0 && $value != 1) {
            throw new SphinxQLException('Option value can be only 1 or 0');
        }

        $this->options[$key] = $value;
        return $this;
    }

    /**
     * Set document parameter for CALL PQ
     *
     * @param array|string $documents
     * @return $this
     */
    public function documents($documents)
    {
        $this->documents = $documents;

        return $this;
    }

    /**
     * Set options for CALL PQ
     *
     * @param array $options
     * @return $this
     * @throws SphinxQLException
     */
    public function options(array $options)
    {
        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }
        return $this;
    }


    /**
     * Get and prepare options for CALL PQ
     *
     * @return string string
     */
    protected function getOptions()
    {
        $options = '';
        if (!empty($this->options)) {
            foreach ($this->options as $option => $value) {
                $options .= ', ' . $value . ' ' . $option;
            }
        }

        return $options;
    }

    private function isAssocArray(array $arr)
    {
        if (array() === $arr) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Get documents for CALL PQ. If option setted JSON - returns json_encoded
     *
     *
     * If expect json = 0:
     *      - doc can be 'catch me'
     *      - doc can be multiple ['catch me if can','catch me']
     *
     * If expect json = 1:
     *      - doc can be associate array ['foo'=>'bar']
     *      - doc can be array of associate arrays [['foo'=>'bar'], ['foo1'=>'bar1']]
     *
     *
     * @return string
     * @throws SphinxQLException
     */
    protected function getDocuments()
    {
        if (!empty($this->documents)) {

            if ($this->options[self::OPTION_DOC_JSON]) {
                if (!is_array($this->documents)) {
                    throw new SphinxQLException(
                        'Options sets as json but documents is string (associate array expected)');
                }

                if (!$this->isAssocArray($this->documents) && !is_array($this->documents[0])) {
                    throw new SphinxQLException('Documents array must be associate');
                }

                return '\'' . json_encode($this->documents) . '\'';
            } else {
                if (is_array($this->documents)) {
                    return '(\'' . implode('\', \'', $this->documents) . '\')';
                } else {
                    return '\'' . $this->documents . '\'';
                }
            }


        }
        throw new SphinxQLException('Documents can\'t be empty');
    }


    /**
     * Set type
     *
     * @return $this
     */
    public function callPQ()
    {
        $this->type = 'call';
        return $this;
    }
}