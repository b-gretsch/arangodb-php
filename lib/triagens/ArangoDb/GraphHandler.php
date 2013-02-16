<?php

/**
 * ArangoDB PHP client: graph handler
 *
 * @package   ArangoDbPhpClient
 * @author    Jan Steemann
 * @author    Frank Mayer
 * @copyright Copyright 2012, triagens GmbH, Cologne, Germany
 *
 * @since     1.2
 */

namespace triagens\ArangoDb;

/**
 * A graph handler that manages graphs.
 * It does so by issuing the
 * appropriate HTTP requests to the server.
 *
 * @package ArangoDbPhpClient
 * @since   1.2
 */
class GraphHandler extends
    DocumentHandler
{

    /**
     * documents array index
     */
    const ENTRY_GRAPH = 'graph';

    /**
     * vertex parameter
     */
    const OPTION_VERTICES = 'vertices';

    /**
     * direction parameter
     */
    const OPTION_EDGES = 'edges';

    /**
     * direction parameter
     */
    const OPTION_KEY = '_key';

    /**
     * example parameter
     */
    const KEY_FROM = '_from';

    /**
     * example parameter
     */
    const KEY_TO = '_to';


    /**
     * Create a graph
     *
     * This will create a graph using the given graph object and return an array of the created graph object's attributes.
     *
     * This will throw if the graph cannot be created
     *
     * @throws Exception
     *
     * @param Graph - $graph - The graph object which holds the information of the graph to be created
     *
     * @return array - an array of the created graph object's attributes.
     * @since   1.2
     *
     * @example ArangoDb/examples/graph.php How to use this function
     */
    public function createGraph(Graph $graph)
    {
        $params   = array(
            self::OPTION_KEY      => $graph->getKey(),
            self::OPTION_VERTICES => $graph->getVerticesCollection(),
            self::OPTION_EDGES    => $graph->getEdgesCollection()
        );
        $url      = UrlHelper::appendParamsUrl(Urls::URL_GRAPH, $params);
        $response = $this->getConnection()->post($url, $this->getConnection()->json_encode_wrapper($params));
        $json     = $response->getJson();

        $graph->setInternalId($json['graph'][Graph::ENTRY_ID]);
        $graph->setRevision($json['graph'][Graph::ENTRY_REV]);

        return $graph->getAll();
    }


    /**
     * Drop a graph and remove all its vertices and edges, also drops vertex and edge collections
     *
     * @throws Exception
     *
     * @param string $graph - graph name as a string
     *
     * @return bool - always true, will throw if there is an error
     * @since 1.2
     */
    public function dropGraph($graph)
    {

        $url = UrlHelper::buildUrl(Urls::URL_GRAPH, $graph);
        $this->getConnection()->delete($url);

        return true;
    }


    /**
     * Get a graph's properties
     *
     * @throws Exception
     *
     * @param string $graph - graph name as a string
     *
     * @return bool - Returns an array of attributes. Will throw if there is an error
     * @since 1.2
     */
    public function properties($graph)
    {

        $url         = UrlHelper::buildUrl(Urls::URL_GRAPH, $graph);
        $result      = $this->getConnection()->get($url);
        $resultArray = $result->getJson();

        return $resultArray['graph'];
    }


    /**
     * save a vertex to a graph
     *
     * This will add the vertex-document to the graph and return the vertex id
     *
     * This will throw if the vertex cannot be saved
     *
     * @throws Exception
     *
     * @param mixed    $graphName - the name of the graph
     * @param Document $document  - the vertex to be added
     *
     * @return mixed - id of vertex created
     * @since 1.2
     */
    public function saveVertex($graphName, Document $document)
    {
        $data = $document->getAll();
        $url  = UrlHelper::buildUrl(Urls::URL_GRAPH, $graphName, Urls::URLPART_VERTEX);

        $response = $this->getConnection()->post($url, $this->getConnection()->json_encode_wrapper($data));

        $jsonArray = $response->getJson();
        $vertex    = $jsonArray['vertex'];
        $id        = $vertex['_id'];
        @list(, $documentId) = explode('/', $id, 2);

        $document->setInternalId($vertex[Vertex::ENTRY_ID]);
        $document->setRevision($vertex[Vertex::ENTRY_REV]);

        if ($documentId != $document->getId()) {
            throw new ClientException('Got an invalid response from the server');
        }

        return $document->getId();
    }


    /**
     * Get a single vertex from a graph
     *
     * This will throw if the vertex cannot be fetched from the server
     *
     * @throws Exception
     *
     * @param string $graphName  - the graph name as a string
     * @param mixed  $vertexId   - the vertex identifier
     * @param array  $options    - optional, an array of options
     * <p>Options are :
     * <li>'includeInternals' - true to include the internal attributes. Defaults to false</li>
     * <li>'ignoreHiddenAttributes' - true to show hidden attributes. Defaults to false</li>
     * </p>
     *
     * @return Document - the vertex document fetched from the server
     * @since 1.2
     */
    public function getVertex($graphName, $vertexId, array $options = array())
    {
        $url      = UrlHelper::buildUrl(Urls::URL_GRAPH, $graphName, Urls::URLPART_VERTEX, $vertexId);
        $response = $this->getConnection()->get($url);

        $jsonArray = $response->getJson();
        $vertex    = $jsonArray['vertex'];

        return Vertex::createFromArray($vertex, $options);
    }


    /**
     * Replace an existing vertex in a graph, identified graph name and vertex id
     *
     * This will update the vertex on the server
     *
     * This will throw if the vertex cannot be Replaced
     *
     * If policy is set to error (locally or globally through the connectionoptions)
     * and the passed document has a _rev value set, the database will check
     * that the revision of the to-be-replaced vertex is the same as the one given.
     *
     * @throws Exception
     *
     * @param string    $graphName    - the graph name as string
     * @param mixed     $vertexId     - the vertex id as string or number
     * @param Document  $document     - the vertex-document to be updated
     * @param mixed     $options      - optional, an array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     * <p>Options are :
     * <li>'policy' - update policy to be used in case of conflict ('error', 'last' or NULL [use default])</li>
     * <li>'waitForSync' - can be used to force synchronisation of the document replacement operation to disk even in case that the waitForSync flag had been disabled for the entire collection</li>
     * </p>
     *
     * @return bool - always true, will throw if there is an error
     *
     * @since 1.2
     */
    public function ReplaceVertex($graphName, $vertexId, Document $document, $options = array())
    {
        // This preserves compatibility for the old policy parameter.
        $params = array();
        $params = $this->validateAndIncludePolicyInParams($options, $params, ConnectionOptions::OPTION_REPLACE_POLICY);
        $params = $this->includeOptionsInParams(
            $options, $params, array(
                                    'waitForSync' => $this->getConnection()->getOption(
                                        ConnectionOptions::OPTION_WAIT_SYNC
                                    )
                               )
        );

        $revision = $document->getRevision();
        if (!is_null($revision)) {
            $params[ConnectionOptions::OPTION_REVISION] = $revision;
        }

        $data = $document->getAll();
        $url  = UrlHelper::buildUrl(Urls::URL_GRAPH, $graphName, Urls::URLPART_VERTEX, $vertexId);

        $response = $this->getConnection()->PUT($url, $this->getConnection()->json_encode_wrapper($data));

        $jsonArray = $response->getJson();
        $vertex    = $jsonArray['vertex'];
        $id        = $vertex['_id'];
        @list(, $documentId) = explode('/', $id, 2);

        $document->setInternalId($vertex[Vertex::ENTRY_ID]);
        $document->setRevision($vertex[Vertex::ENTRY_REV]);

        if ($documentId != $document->getId()) {
            throw new ClientException('Got an invalid response from the server');
        }

        return true;
    }

    /**
     * Update an existing vertex in a graph, identified by graph name and vertex id
     *
     * This will update the vertex on the server
     *
     * This will throw if the vertex cannot be updated
     *
     * If policy is set to error (locally or globally through the connectionoptions)
     * and the passed vertex-document has a _rev value set, the database will check
     * that the revision of the to-be-replaced document is the same as the one given.
     *
     * @throws Exception
     *
     * @param string   $graphName   - the graph name as string
     * @param mixed    $vertexId    - the vertex id as string or number
     * @param Document $document    - the patch vertex-document which contains the attributes and values to be updated
     * @param mixed    $options     - optional, an array of options (see below)
     * <p>Options are :
     * <li>'policy' - update policy to be used in case of conflict ('error', 'last' or NULL [use default])</li>
     * <li>'keepNull' - can be used to instruct ArangoDB to delete existing attributes instead setting their values to null. Defaults to true (keep attributes when set to null)</li>
     * <li>'waitForSync' - can be used to force synchronisation of the document update operation to disk even in case that the waitForSync flag had been disabled for the entire collection</li>
     * </p>
     *
     * @return bool - always true, will throw if there is an error
     * @since 1.2
     */
    public function updateVertex($graphName, $vertexId, Document $document, $options = array())
    {
        // This preserves compatibility for the old policy parameter.
        $params = array();
        $params = $this->validateAndIncludePolicyInParams($options, $params, ConnectionOptions::OPTION_UPDATE_POLICY);
        $params = $this->includeOptionsInParams(
            $options, $params, array(
                                    'waitForSync' => $this->getConnection()->getOption(
                                        ConnectionOptions::OPTION_WAIT_SYNC
                                    ),
                                    'keepNull'    => true,
                               )
        );

        $revision = $document->getRevision();
        if (!is_null($revision)) {
            $params[ConnectionOptions::OPTION_REVISION] = $revision;
        }

        $url    = UrlHelper::buildUrl(Urls::URL_GRAPH, $graphName, Urls::URLPART_VERTEX, $vertexId);
        $url    = UrlHelper::appendParamsUrl($url, $params);
        $result = $this->getConnection()->patch($url, $this->getConnection()->json_encode_wrapper($document->getAll()));

        return true;
    }


    /**
     * Remove a vertex from a graph, identified by the graph name and vertex id
     *
     * @throws Exception
     *
     * @param mixed  $graphName  - the graph name as string
     * @param mixed  $vertexId   - the vertex id as string or number
     * @param  mixed $revision   - optional, the revision of the vertex to be deleted
     * @param mixed  $options    - optional, an array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     * <p>Options are :
     * <li>'policy' - update policy to be used in case of conflict ('error', 'last' or NULL [use default])</li>
     * <li>'waitForSync' - can be used to force synchronisation of the document removal operation to disk even in case that the waitForSync flag had been disabled for the entire collection</li>
     * </p>
     *
     * @return bool - always true, will throw if there is an error
     * @since 1.2
     */
    public function removeVertex($graphName, $vertexId, $revision = null, $options = array())
    {
        // This preserves compatibility for the old policy parameter.
        $params = array();
        $params = $this->validateAndIncludePolicyInParams($options, $params, ConnectionOptions::OPTION_DELETE_POLICY);
        $params = $this->includeOptionsInParams(
            $options, $params, array(
                                    'waitForSync' => $this->getConnection()->getOption(
                                        ConnectionOptions::OPTION_WAIT_SYNC
                                    ),
                                    'keepNull'    => true,
                               )
        );

        if (!is_null($revision)) {
            $params[ConnectionOptions::OPTION_REVISION] = $revision;
        }

        $url    = UrlHelper::buildUrl(Urls::URL_GRAPH, $graphName, Urls::URLPART_VERTEX, $vertexId);
        $url    = UrlHelper::appendParamsUrl($url, $params);
        $result = $this->getConnection()->delete($url);

        return true;
    }


    /**
     * save an edge to a graph
     *
     * This will save the edge to the graph and return the edges-document's id
     *
     * This will throw if the edge cannot be saved
     *
     * @throws Exception
     *
     * @param mixed    $graphName    - the graph name as string
     * @param mixed    $from         - the 'from' vertex
     * @param mixed    $to           - the 'to' vertex
     * @param mixed    $label        - (optional) a label for the edge
     * @param Edge     $document     - the edge-document to be added
     *
     * @return mixed - id of edge created
     * @since 1.2
     */
    public function saveEdge($graphName, $from, $to, $label = null, Edge $document)
    {
        if (!is_null($label)) {
            $document->set('$label', $label);
        }
        $document->setFrom($from);
        $document->setTo($to);
        $data                 = $document->getAll();
        $data[self::KEY_FROM] = $from;
        $data[self::KEY_TO]   = $to;

        $url      = UrlHelper::buildUrl(Urls::URL_GRAPH, $graphName, Urls::URLPART_EDGE);
        $response = $this->getConnection()->post($url, $this->getConnection()->json_encode_wrapper($data));

        //        $location = $response->getLocationHeader();
        //        if (!$location) {
        //            throw new ClientException('Did not find location header in server response');
        //        }


        $jsonArray = $response->getJson();
        $edge      = $jsonArray['edge'];
        $id        = $edge['_id'];
        @list(, $documentId) = explode('/', $id, 2);

        $document->setInternalId($edge[Edge::ENTRY_ID]);
        $document->setRevision($edge[Edge::ENTRY_REV]);

        if ($documentId != $document->getId()) {
            throw new ClientException('Got an invalid response from the server');
        }

        return $document->getId();
    }


    /**
     * Get a single edge from a graph
     *
     * This will throw if the edge cannot be fetched from the server
     *
     * @throws Exception
     *
     * @param mixed $graphName  - collection id as a string or number
     * @param mixed $edgeId     - edge identifier
     * @param array $options    - optional, array of options
     * <p>Options are :
     * <li>'includeInternals' - true to include the internal attributes. Defaults to false</li>
     * <li>'ignoreHiddenAttributes' - true to show hidden attributes. Defaults to false</li>
     * </p>
     *
     * @return Document - the edge document fetched from the server
     * @since 1.2
     */
    public function getEdge($graphName, $edgeId, array $options = array())
    {
        $url      = UrlHelper::buildUrl(Urls::URL_GRAPH, $graphName, Urls::URLPART_EDGE, $edgeId);
        $response = $this->getConnection()->get($url);

        $jsonArray = $response->getJson();
        $edge      = $jsonArray['edge'];

        return Edge::createFromArray($edge, $options);
    }


    /**
     * Replace an existing edge in a graph, identified graph name and edge id
     *
     * This will replace the edge on the server
     *
     * This will throw if the edge cannot be Replaced
     *
     * If policy is set to error (locally or globally through the connectionoptions)
     * and the passed document has a _rev value set, the database will check
     * that the revision of the to-be-replaced edge is the same as the one given.
     *
     * @throws Exception
     *
     * @param mixed    $graphName     - graph name as string or number
     * @param mixed    $edgeId        - edge id as string or number
     * @param mixed    $label         - (optional) label for the edge
     * @param Edge     $document      - edge document to be updated
     * @param mixed    $options       - optional, array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     * <p>Options are :
     * <li>'policy' - update policy to be used in case of conflict ('error', 'last' or NULL [use default])</li>
     * <li>'waitForSync' - can be used to force synchronisation of the document replacement operation to disk even in case that the waitForSync flag had been disabled for the entire collection</li>
     * </p>
     *
     * @return bool - always true, will throw if there is an error
     *
     * @since 1.2
     */
    public function ReplaceEdge($graphName, $edgeId, $label, Edge $document, $options = array())
    {
        // This preserves compatibility for the old policy parameter.
        $params = array();
        $params = $this->validateAndIncludePolicyInParams($options, $params, ConnectionOptions::OPTION_REPLACE_POLICY);
        $params = $this->includeOptionsInParams(
            $options, $params, array(
                                    'waitForSync' => $this->getConnection()->getOption(
                                        ConnectionOptions::OPTION_WAIT_SYNC
                                    )
                               )
        );

        $revision = $document->getRevision();
        if (!is_null($revision)) {
            $params[ConnectionOptions::OPTION_REVISION] = $revision;
        }

        $data = $document->getAll();
        if (!is_null($label)) {
            $document->set('$label', $label);
        }
        $url = UrlHelper::buildUrl(Urls::URL_GRAPH, $graphName, Urls::URLPART_EDGE, $edgeId);

        $response = $this->getConnection()->PUT($url, $this->getConnection()->json_encode_wrapper($data));

        $jsonArray = $response->getJson();
        $edge      = $jsonArray['edge'];
        $id        = $edge['_id'];
        @list(, $documentId) = explode('/', $id, 2);

        $document->setInternalId($edge[Edge::ENTRY_ID]);
        $document->setRevision($edge[Edge::ENTRY_REV]);

        if ($documentId != $document->getId()) {
            throw new ClientException('Got an invalid response from the server');
        }

        return true;
    }

    /**
     * Update an existing edge in a graph, identified by graph name and edge id
     *
     * This will update the edge on the server
     *
     * This will throw if the edge cannot be updated
     *
     * If policy is set to error (locally or globally through the connectionoptions)
     * and the passed edge-document has a _rev value set, the database will check
     * that the revision of the to-be-replaced document is the same as the one given.
     *
     * @throws Exception
     *
     * @param string   $graphName     - graph name as string
     * @param mixed    $edgeId        - edge id as string or number
     * @param mixed    $label         - (optional) label for the edge
     * @param Edge     $document      - patch edge-document which contains the attributes and values to be updated
     * @param mixed    $options       - optional, array of options (see below)
     * <p>Options are :
     * <li>'policy' - update policy to be used in case of conflict ('error', 'last' or NULL [use default])</li>
     * <li>'keepNull' - can be used to instruct ArangoDB to delete existing attributes instead setting their values to null. Defaults to true (keep attributes when set to null)</li>
     * <li>'waitForSync' - can be used to force synchronisation of the document update operation to disk even in case that the waitForSync flag had been disabled for the entire collection</li>
     * </p>
     *
     * @return bool - always true, will throw if there is an error
     * @since 1.2
     */
    public function updateEdge($graphName, $edgeId, $label, Edge $document, $options = array())
    {
        // This preserves compatibility for the old policy parameter.
        $params = array();
        $params = $this->validateAndIncludePolicyInParams($options, $params, ConnectionOptions::OPTION_UPDATE_POLICY);
        $params = $this->includeOptionsInParams(
            $options, $params, array(
                                    'waitForSync' => $this->getConnection()->getOption(
                                        ConnectionOptions::OPTION_WAIT_SYNC
                                    ),
                                    'keepNull'    => true,
                               )
        );
        $policy = null;

        $revision = $document->getRevision();
        if (!is_null($revision)) {
            $params[ConnectionOptions::OPTION_REVISION] = $revision;
        }

        if (!is_null($label)) {
            $document->set('$label', $label);
        }

        $url    = UrlHelper::buildUrl(Urls::URL_GRAPH, $graphName, Urls::URLPART_EDGE, $edgeId);
        $url    = UrlHelper::appendParamsUrl($url, $params);
        $result = $this->getConnection()->patch($url, $this->getConnection()->json_encode_wrapper($document->getAll()));

        return true;
    }


    /**
     * Remove a edge from a graph, identified by the graph name and edge id
     *
     * @throws Exception
     *
     * @param mixed  $graphName   - graph name as string or number
     * @param mixed  $edgeId      - edge id as string or number
     * @param  mixed $revision    - optional revision of the edge to be deleted
     * @param mixed  $options     - optional, array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     * <p>Options are :
     * <li>'policy' - update policy to be used in case of conflict ('error', 'last' or NULL [use default])</li>
     * <li>'waitForSync' - can be used to force synchronisation of the document removal operation to disk even in case that the waitForSync flag had been disabled for the entire collection</li>
     * </p>
     *
     * @return bool - always true, will throw if there is an error
     * @since 1.2
     */
    public function removeEdge($graphName, $edgeId, $revision = null, $options = array())
    {
        // This preserves compatibility for the old policy parameter.
        $params = array();
        $params = $this->validateAndIncludePolicyInParams($options, $params, ConnectionOptions::OPTION_DELETE_POLICY);
        $params = $this->includeOptionsInParams(
            $options, $params, array(
                                    'waitForSync' => $this->getConnection()->getOption(
                                        ConnectionOptions::OPTION_WAIT_SYNC
                                    ),
                                    'keepNull'    => true,
                               )
        );
        if (!is_null($revision)) {
            $params[ConnectionOptions::OPTION_REVISION] = $revision;
        }

        $url    = UrlHelper::buildUrl(Urls::URL_GRAPH, $graphName, Urls::URLPART_EDGE, $edgeId);
        $url    = UrlHelper::appendParamsUrl($url, $params);
        $result = $this->getConnection()->delete($url);

        return true;
    }


    /**
     * Just throw an exception if add() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param mixed    $collectionId - collection id as string or number
     * @param Document $document     - the document to be added
     * @param bool     $create       - create the collection if it does not yet exist
     *
     * @since 1.2
     */
    public function add($collectionId, Document $document, $create = null)
    {
        throw new ClientException("Graphs don't have a save() method. Please use saveVertex() or saveEdge()");
    }


    /**
     * Just throw an exception if save() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param mixed    $collectionId - collection id as string or number
     * @param Document $document     - the document to be added
     * @param bool     $create       - create the collection if it does not yet exist
     *
     * @since 1.2
     */
    public function save($collectionId, Document $document, $create = null)
    {
        throw new ClientException("Graphs don't have a save() method. Please use saveVertex() or saveEdge()");
    }


    /**
     * Just throw an exception if get() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param mixed $collectionId - collection id as a string or number
     * @param mixed $documentId   - document identifier
     * @param array $options      - optional, array of options
     */
    public function get($collectionId, $documentId, array $options = array())
    {
        throw new ClientException("Graphs don't support this method. Please use getVertex() or getEdge()");
    }


    /**
     * Just throw an exception if getById() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param mixed $collectionId - collection id as a string or number
     * @param mixed $documentId   - document identifier
     * @param array $options      - optional, array of options
     */
    public function getById($collectionId, $documentId, array $options = array())
    {
        throw new ClientException("Graphs don't support this method. Please use getVertex()");
    }


    /**
     * Just throw an exception if getAllIds() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param mixed $collectionId - collection id as string or number
     */
    public function getAllIds($collectionId)
    {
        throw new ClientException("Graphs don't support this method.");
    }


    /**
     * Just throw an exception if getByExample() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param mixed $collectionId - collection id as string or number
     * @param mixed $document     - the example document as a Document object or an array
     * @param bool  $sanitize     - remove _id and _rev attributes from result documents
     */
    public function getByExample($collectionId, $document, $sanitize = false)
    {
        throw new ClientException("Graphs don't support this method.");
    }


    /**
     * Just throw an exception if update() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param Document $document - The patch document that will update the document in question
     * @param mixed    $options  - optional, array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     */
    public function update(Document $document, $options = array())
    {
        throw new ClientException("Graphs don't support this method.");
    }


    /**
     * Just throw an exception if replace() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param Document $document - document to be updated
     * @param mixed    $options  - optional, array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     */
    public function replace(Document $document, $options = array())
    {
        throw new ClientException("Graphs don't support this method.");
    }


    /**
     * Just throw an exception if updateById() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param mixed    $collectionId - collection id as string or number
     * @param mixed    $documentId   - document id as string or number
     * @param Document $document     - patch document which contains the attributes and values to be updated
     * @param mixed    $options      - optional, array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     */
    public function updateById($collectionId, $documentId, Document $document, $options = array())
    {
        throw new ClientException("Graphs don't support this method.");
    }


    /**
     *
     * Just throw an exception if replaceById() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param mixed    $collectionId - collection id as string or number
     * @param mixed    $documentId   - document id as string or number
     * @param Document $document     - document to be updated
     * @param mixed    $options      - optional, array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     */
    public function replaceById($collectionId, $documentId, Document $document, $options = array())
    {
        throw new ClientException("Graphs don't support this method.");
    }


    /**
     *
     * Just throw an exception if delete() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param Document $document - document to be updated
     * @param mixed    $options  - optional, array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     */
    public function delete(Document $document, $options = array())
    {
        throw new ClientException("Graphs don't support this method.");
    }


    /**
     *
     * Just throw an exception if remove() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param Document $document - document to be removed
     * @param mixed    $options  - optional, array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     */
    public function remove(Document $document, $options = array())
    {
        throw new ClientException("Graphs don't support this method.");
    }


    /**
     *
     * Just throw an exception if deleteById() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param mixed  $collectionId - collection id as string or number
     * @param mixed  $documentId   - document id as string or number
     * @param  mixed $revision     - optional revision of the document to be deleted
     * @param mixed  $options      - optional, array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     */
    public function deleteById($collectionId, $documentId, $revision = null, $options = array())
    {
        throw new ClientException("Graphs don't support this method.");
    }


    /**
     *
     * Just throw an exception if removeById() is called on a graph.
     *
     * @internal
     * @throws ClientException
     *
     * @param mixed  $collectionId - collection id as string or number
     * @param mixed  $documentId   - document id as string or number
     * @param  mixed $revision     - optional revision of the document to be deleted
     * @param mixed  $options      - optional, array of options (see below) or the boolean value for $policy (for compatibility prior to version 1.1 of this method)
     */
    public function removeById($collectionId, $documentId, $revision = null, $options = array())
    {
        throw new ClientException("Graphs don't support this method.");
    }
}