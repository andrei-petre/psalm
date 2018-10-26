<?php
namespace Psalm\Tests\FileUpdates;

use Psalm\Checker\FileChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Provider\Providers;
use Psalm\Tests\TestConfig;
use Psalm\Tests\Provider;

class TemporaryUpdateTest extends \Psalm\Tests\TestCase
{
    /**
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        FileChecker::clearCache();

        $this->file_provider = new \Psalm\Tests\Provider\FakeFileProvider();

        $config = new TestConfig();
        $config->throw_exception = false;

        $providers = new Providers(
            $this->file_provider,
            new \Psalm\Tests\Provider\ParserInstanceCacheProvider(),
            null,
            null,
            new Provider\FakeFileReferenceCacheProvider()
        );

        $this->project_checker = new ProjectChecker(
            $config,
            $providers,
            false,
            true,
            ProjectChecker::TYPE_CONSOLE,
            1,
            false
        );

        $this->project_checker->infer_types_from_usage = true;
    }

    /**
     * @dataProvider providerTestErrorFix
     *
     * @param array<int, array<string, string>> $file_stages
     * @param array<int, int> $error_positions
     * @param array<string, string> $error_levels
     *
     * @return void
     */
    public function testErrorFix(
        array $file_stages,
        array $error_positions,
        array $error_levels = []
    ) {
        $this->project_checker->diff_methods = true;

        $codebase = $this->project_checker->getCodebase();

        $config = $codebase->config;

        foreach ($error_levels as $error_type => $error_level) {
            $config->setCustomErrorLevel($error_type, $error_level);
        }

        $start_files = array_shift($file_stages);

        // first batch
        foreach ($start_files as $file_path => $contents) {
            $this->file_provider->registerFile($file_path, $contents);
            $codebase->file_provider->openFile($file_path);
            $codebase->addFilesToAnalyze([$file_path => $file_path]);
        }

        $codebase->scanFiles();

        $codebase->analyzer->analyzeFiles($this->project_checker, 1, false);

        $data = \Psalm\IssueBuffer::clear();

        $found_positions = array_map(
            /** @param array{from: int} $a */
            function (array $a) : int {
                return $a['from'];
            },
            $data
        );

        $this->assertSame($error_positions[0], $found_positions);

        foreach ($file_stages as $i => $file_stage) {
            foreach ($file_stage as $file_path => $contents) {
                $codebase->addTemporaryFileChanges(
                    $file_path,
                    [new \LanguageServerProtocol\TextDocumentContentChangeEvent(null, null, $contents)]
                );
            }

            foreach ($file_stage as $file_path => $contents) {
                $codebase->addFilesToAnalyze([$file_path => $file_path]);
            }

            $codebase->analyzer->analyzeFiles($this->project_checker, 1, false);

            $data = \Psalm\IssueBuffer::clear();

            $found_positions = array_map(
                /** @param array{from: int} $a */
                function (array $a) : int {
                    return $a['from'];
                },
                $data
            );

            $this->assertSame($error_positions[$i + 1], $found_positions);
        }
    }

    /**
     * @return array
     */
    public function providerTestErrorFix()
    {
        return [
            'fixMissingColonSyntaxError' => [
                [
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : void {
                                    $a = 5;
                                    echo $a;
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : void {
                                    $a = 5
                                    echo $a;
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : void {
                                    $a = 5;
                                    echo $a;
                                }
                            }',
                    ],
                ],
                'error_positions' => [[], [230], []],
            ],
            'addReturnTypesToSingleMethod' => [
                [
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() {
                                    return 5;
                                }

                                public function bar() {
                                    $a = $_GET["foo"];
                                    return $this->foo();
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : int {
                                    return 5;
                                }

                                public function bar() {
                                    $a = $_GET["foo"];
                                    return $this->foo();
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : int {
                                    return 5;
                                }

                                public function bar() : int {
                                    $a = $_GET["foo"];
                                    return $this->foo();
                                }
                            }',
                    ],
                ],
                'error_positions' => [[136, 317, 273], [323, 279], [329]],
            ],
            'addSpaceAffectingOffsets' => [
                [
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : int {
                                    $a = 5;
                                    return 5;
                                }

                                public function bar() : int {
                                    $a = $_GET["foo"];
                                    return $this->foo();
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : int {
                                    $a = 5;

                                    return 5;
                                }

                                public function bar() : int {
                                    $a = $_GET["foo"];
                                    return $this->foo();
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : int {
                                    $a = 5;


                                    return 5;
                                }

                                public function bar() : int {
                                    $a = $_GET["foo"];
                                    return $this->foo();
                                }
                            }',
                    ],
                ],
                'error_positions' => [[373], [374], [375]],
                [
                    'MixedAssignment' => \Psalm\Config::REPORT_INFO,
                ]
            ],
            'fixReturnType' => [
                [
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : string {
                                    return 5;
                                }

                                public function bar() : int {
                                    return "hello";
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : string {
                                    return "hello";
                                }

                                public function bar() : int {
                                    return "hello";
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : string {
                                    return "hello";
                                }

                                public function bar() : int {
                                    return 5;
                                }
                            }',
                    ],
                ],
                'error_positions' => [[189, 144, 332, 290], [338, 296], []],
                [
                    'MissingReturnType' => \Psalm\Config::REPORT_INFO,
                ]
            ],
            'bridgeStatements' => [
                [
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() {
                                    return 5;
                                }

                                public function bar() {
                                    return "hello";
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : int {
                                    return 5;
                                }

                                public function bar() {
                                    return "hello";
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : int {
                                    return "hello";
                                }
                            }',
                    ],
                ],
                'error_positions' => [[136, 273], [279], [186, 144]],
                [
                    'MissingReturnType' => \Psalm\Config::REPORT_INFO,
                ]
            ],
            'colonReturnType' => [
                [
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() {
                                    return 5;
                                }

                                public function bar() {
                                    return "hello";
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public function foo() : {
                                    return 5;
                                }

                                public function bar() {
                                    return "hello";
                                }
                            }',
                    ],
                ],
                'error_positions' => [[136, 273], [275, 144, 136]],
                [
                    'MissingReturnType' => \Psalm\Config::REPORT_INFO,
                ]
            ],
            'noChangeJustWeirdDocblocks' => [
                [
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public $aB = 5;

                                const F = 1;

                                public function bat() : void {
                                    $a = 1;
                                }

                                /*
                                 * another
                                 */
                                /**
                                 * @return void
                                 */
                                public function foo() {
                                    $a = 1;
                                }

                                // this is one line
                                // this is another
                                public function bar() : void {
                                    $b = 1;
                                }
                            }',
                    ],
                    [
                        getcwd() . DIRECTORY_SEPARATOR . 'A.php' => '<?php
                            namespace Foo;

                            class A {
                                public $aB = 5;

                                const F = 1;

                                public function bat() : void {
                                    $a = 1;
                                    $b = 1;
                                }

                                /*
                                 * another
                                 */
                                /**
                                 * @return void
                                 */
                                public function foo() {
                                    $a = 1;
                                }

                                // this is one line
                                // this is another
                                public function bar() : void {
                                    $b = 1;
                                }
                            }',
                    ],
                ],
                'error_positions' => [[120], [120]],
            ],
        ];
    }
}
