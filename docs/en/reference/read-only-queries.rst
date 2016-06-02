Read-Only queries
=================

You can instruct a query to not manage the results of the query. This is useful
if you don't want to have changes to those documents persisted to the database.
To do this you can use the ``readOnly()`` method on the query builder:

.. code-block:: php

    <?php

    $users = $dm
        ->createQueryBuilder('User')
        ->readOnly(true)
        ->getQuery()
        ->execute();

You will receive a result cursor with hydrated documents but these documents will
not be managed by the DocumentManager. This includes all embedded documents contained
within a document.

.. note::

    Due to a limitation in the hydrators, references to other documents are not
    loaded as read-only documents. Referenced documents will be managed by the
    DocumentManager and all changes will be persisted to the database on the next
    flush.

You can also use this to hydrate data from an external source:

.. code-block:: php

    <?php

    $data = $api->getData($userId);
    $user = new User();
    $dm->->getHydratorFactory()->hydrate($document, $data, [ Query::HINT_READ_ONLY => true ]);

The resulting user object will be persisted with the data fetched from an external
source. Due to the read-only query hint it will not be managed by the document manager.

.. note::

    If you want to have a read-only document managed later on, always use the
    `merge` method provided by the DocumentManager. Managing a read-only document
    using `persist` can cause unwanted behavior.
