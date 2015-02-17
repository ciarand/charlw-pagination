<?php

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

$filename = __DIR__ . preg_replace('#(\?.*)$#', '', $_SERVER['REQUEST_URI']);
if (php_sapi_name() === 'cli-server' && is_file($filename)) {
    return false;
}

require_once __DIR__ . '/../vendor/autoload.php';

$app = new Silex\Application();

$app["debug"] = true;

$app->register(new Silex\Provider\DoctrineServiceProvider(), [
    'db.options' => [
        'driver' => 'pdo_sqlite',
        'path'   => __DIR__ . '/../app.db',
    ],
]);

$app->register(new Silex\Provider\TwigServiceProvider(), [
    'twig.path' => __DIR__ . '/../views',
]);

$app->get("/seed", function () use ($app) {
    return $app["twig"]->render("seed_form.twig");
});

$plaintext = function ($text) {
    return new Response($text, 200, ["Content-Type" => "text/plain"]);
};
$app->get("/license", function () use ($plaintext) {
    return $plaintext(file_get_contents(__DIR__ . "/../LICENSE"));
});

$app->get("/schema", function () use ($app) {
    return $app["twig"]->render("schema_form.twig");
});

$redirect = function ($path) use ($app) {
    return function () use ($path, $app) {
        return $app->redirect($path);
    };
};

$app->get("/", $redirect("/cats"));
$app->get("/cats/", $redirect("/cats"));

$app->get("/cats/{page}", function ($page) use ($app) {
    // we're going to show 100 cats a page, no more no less:

    /** @var Connection $db */
    $db = $app["db"];

    // first we need a count of the number of cats (we use this to generate the pagination view
    // stuff)
    $total = $db->fetchColumn("SELECT COUNT(id) FROM cool_cats");

    $numPages = (intval($total) / 100) + 1;
    if ($page >= $numPages) {
        return $app->redirect("/cats/" . ($numPages - 1));
    }

    // next figure out the offset to use
    $offset = intval((intval($page) - 1) * 100);

    // now run the query
    $statement = $db->query(
        // the limit is the number to grab
        // the offset is where we start from
        // the order is backwards (most recent stuff goes on page 1, oldest goes on page 100 / w.e.)
        "SELECT id, name, color FROM cool_cats ORDER BY id DESC LIMIT 100 OFFSET {$offset} "
    );

    return $app["twig"]->render("cats.twig", [
        "page"  => $page,
        "cats"  => $statement->fetchAll(),
        "total" => $total,
    ]);
})->value("page", 1)->assert("page", '\d+');
// create the db schema
$app->post("/schema", function () use ($app) {
    /** @var Connection $db */
    $db = $app["db"];

    $construct = <<<SQL
    CREATE TABLE IF NOT EXISTS cool_cats (
        id INTEGER NOT NULL,
        name VARCHAR(255) NOT NULL,
        color VARCHAR(255) NOT NULL,
        PRIMARY KEY(id)
    )
SQL;

    $result = $db->query($construct)->execute();

    return $app["twig"]->render("schema_result.twig", ["success" => $result]);
});

// seed the database
$app->post('/seed/{count}', function ($count) use ($app) {
    $faker = Faker\Factory::create();

    $records = [];

    try {
        for ($i = 0; $i < $count; $i++) {
            $app["db"]->insert("cool_cats", $newRec = [
                "name"  => $faker->name,
                "color" => $faker->colorName
            ]);

            $records[] = $newRec;
        }
    } catch (\Exception $e) {
        return $app["twig"]->render("seed_error.twig", ["exception" => $e]);
    }

    return $app["twig"]->render("seed.twig", ["count" => $count, "records" => $records]);
})->value("count", 100)->assert("count", '\d+');

$app->run();
