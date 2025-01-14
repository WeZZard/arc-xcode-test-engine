<?php
/*
 Copyright 2016-present Google Inc. All Rights Reserved.

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

 http://www.apache.org/licenses/LICENSE-2.0

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
 */

/**
 * Builds, runs, and interprets xcodebuild unit test & coverage results.
 *
 * Requires xcodebuild.
 */
final class XcodeUnitTestEngine extends ArcanistUnitTestEngine {

  private $xcodebuildBinary = 'env NSUnbufferedIO=YES xcodebuild';
  private $covBinary = 'xcrun llvm-cov';

  private $projectRoot;
  private $affectedTests;
  private $xcodebuild;
  private $coverage;
  private $preBuildCommand;
  private $hasCoverageKey;

  public function getEngineConfigurationName() {
    return 'xcode-test-engine';
  }

  protected function supportsRunAllTests() {
    return true;
  }

  public function shouldEchoTestResults() {
    return false; // i.e. this engine does not output its own results.
  }

  private function shouldGenerateCoverage() {
    // getEnableCoverage return value meanings:
    // false: User passed --no-coverage, explicitly disabling coverage.
    // null:  User did not pass any coverage flags. Coverage should generally be enabled if
    //        available.
    // true:  User passed --coverage.
    // https://secure.phabricator.com/T10561
    $arcCoverageFlag = $this->getEnableCoverage();

    return ($arcCoverageFlag !== false) && $this->hasCoverageKey;
  }

  private function checkArcConfigFile() {
    // Throws exception if the config file `.arcconfig` is missing or it's in invalid JSON format. 

    $this->projectRoot = $this->getWorkingCopy()->getProjectRoot();
    $config_path = $this->getWorkingCopy()->getProjectPath('.arcconfig');

    if (!Filesystem::pathExists($config_path)) {
      throw new ArcanistUsageException(
        pht(
          "Unable to find '%s' file to configure xcode-test engine. Create an ".
          "'%s' file in the root directory of the working copy.",
          '.arcconfig',
          '.arcconfig'));
    }

    $data = Filesystem::readFile($config_path);
    try {
      phutil_json_decode($data);
    } catch (PhutilJSONParserException $ex) {
      throw new PhutilProxyException(
        pht(
          "Expected '%s' file to be a valid JSON file, but ".
          "failed to decode '%s'.",
          '.arcconfig',
          $config_path),
        $ex);
    }
  }

  protected function loadEnvironment() {
    
    $this->checkArcConfigFile();

    $config_manager = $this->getConfigurationManager();
    if ($config_manager == null) {
      throw new ArcanistUsageException(
        pht(
          "Unable to setup configuration manager. Make sure that ".
          "unit.engine is set properly in '%s'",
          '.arcconfig'));
    }
    $unit_config = $config_manager->getConfigFromAnySource('unit.xcode');  
    
    if ($unit_config == null) {
      throw new ArcanistUsageException(
        pht(
          "Unable to find '%s' keys in .arcconfig.",
          'unit.xcode'));
    }

    $this->xcodebuild = $unit_config['build'];

    $this->hasCoverageKey = array_key_exists('coverage', $unit_config);

    if ($this->shouldGenerateCoverage()) {
      $this->xcodebuild["enableCodeCoverage"] = "YES";
      $this->coverage = $unit_config['coverage'];
    } else {
      $this->xcodebuild["enableCodeCoverage"] = "NO";
    }

    if (array_key_exists('pre-build', $unit_config)) {
      $this->preBuildCommand = $unit_config['pre-build'];
    }
  }

  public function run() {
    $this->loadEnvironment();

    if (!$this->getRunAllTests()) {
      $paths = $this->getPaths();
      if (empty($paths)) {
        return array();
      }
    }

    $xcodeargs = array();
    foreach ($this->xcodebuild as $key => $value) {
      if ($key == "workspace") {
        $value = $this->projectRoot."/".$value;
      } 
      $xcodeargs []= "-$key \"$value\"";
    }

    if (!empty($this->preBuildCommand)) {
      $future = new ExecFuture($this->preBuildCommand);
      $future->setCWD(Filesystem::resolvePath($this->getWorkingCopy()->getProjectRoot()));
      $future->resolvex();
    }

    // Build and run unit tests
    $future = new ExecFuture('%C %C test',
      $this->xcodebuildBinary, implode(' ', $xcodeargs));

    list($builderror, $xcbuild_stdout, $xcbuild_stderr) = $future->resolve();

    // Error-code 65 is thrown for build/unit test failures.
    if ($builderror !== 0 && $builderror !== 65) {
      return array(id(new ArcanistUnitTestResult())
        ->setName("Xcode test engine")
        ->setUserData($xcbuild_stderr)
        ->setResult(ArcanistUnitTestResult::RESULT_BROKEN));
    }

    // Extract coverage information
    $coverage = null;
    if ($builderror === 0 && $this->shouldGenerateCoverage()) {
      // Get the OBJROOT
      $future = new ExecFuture('%C %C -showBuildSettings test',
        $this->xcodebuildBinary, implode(' ', $xcodeargs));
      $future->setCWD(Filesystem::resolvePath($this->getWorkingCopy()->getProjectRoot()));
      list(, $settings_stdout, ) = $future->resolve();
      if (!preg_match('/OBJROOT = (.+)/', $settings_stdout, $matches)) {
        throw new Exception('Unable to find OBJROOT configuration.');
      }
      $objroot = $matches[1];
      $coverage_dir = dirname($objroot);

      $future = new ExecFuture("find %C -name Coverage.profdata", $coverage_dir);
      list(, $coverage_stdout, ) = $future->resolve();
      $profdata_path = explode("\n", $coverage_stdout)[0];

      $future = new ExecFuture("find %C | grep %C", $coverage_dir, $this->coverage['product']);
      list(, $product_stdout, ) = $future->resolve();
      $product_path = explode("\n", $product_stdout)[0];

      $future = new ExecFuture('%C show -use-color=false -instr-profile "%C" "%C"',
        $this->covBinary, $profdata_path, $product_path);
      $future->setCWD(Filesystem::resolvePath($this->getWorkingCopy()->getProjectRoot()));

      try {
        list($coverage, $coverage_error) = $future->resolvex();
      } catch (CommandException $exc) {
        if ($exc->getError() != 0) {
          throw $exc;
        }
      }
    }
    
    // TODO(featherless): If we publicized the parseCoverageResults method on
    // XcodeTestResultParser we could parseTestResults, then call parseCoverageResults,
    // and the logic here would map the coverage results to the test results. This
    // might be a cleaner approach.

    return id(new XcodeTestResultParser())
      ->setEnableCoverage($this->shouldGenerateCoverage())
      ->setCoverageFile($coverage)
      ->setProjectRoot($this->projectRoot)
      ->setXcodeArgs($xcodeargs)
      ->setStderr($xcbuild_stderr)
      ->parseTestResults(null, $xcbuild_stdout);
  }
}
