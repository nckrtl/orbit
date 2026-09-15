import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


CAPTURE = Path(__file__).resolve().parents[1] / "scripts/capture.py"


class CaptureTest(unittest.TestCase):
    def run_case(self, source, *options, plan=None):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        output = root / "capture"
        command = [sys.executable, str(CAPTURE), "--candidate", "fixture-candidate",
                   "--label", "recorder-test", "--output-dir", str(output),
                   "--no-live", "--timeout", "5", "--idle-timeout", "3"]
        if plan is not None:
            plan_file = root / "input.json"
            plan_file.write_text(json.dumps(plan))
            command += ["--input-plan", str(plan_file)]
        command += list(options) + ["--", sys.executable, "-c", source]
        result = subprocess.run(command, capture_output=True, timeout=9)
        self.assertTrue((output / "summary.json").exists(), result.stderr.decode())
        summary = json.loads((output / "summary.json").read_text())
        frames = [json.loads(line) for line in (output / "frames.jsonl").read_text().splitlines()]
        return result, summary, output, frames

    def test_preserves_child_failure_and_drains_large_final_output(self):
        result, summary, output, _ = self.run_case(
            "import os; os.write(1, b'x'*200000 + b'FINAL-MARKER'); raise SystemExit(7)")
        self.assertEqual(result.returncode, 7)
        self.assertEqual(summary["child_exit_code"], 7)
        self.assertTrue(summary["drained"])
        self.assertEqual((output / "raw.bin").read_bytes(), b"x" * 200000 + b"FINAL-MARKER")

    def test_split_utf8_is_decoded_incrementally(self):
        result, _, output, frames = self.run_case(
            "import os,time; os.write(1,b'\\xe2'); time.sleep(.08); os.write(1,b'\\x94\\x8c hello')")
        self.assertEqual(result.returncode, 0)
        self.assertEqual((output / "transcript.txt").read_text(), "┌ hello")
        self.assertEqual(frames[-1]["lines"][0].strip(), "┌ hello")

    def test_reconstructs_repainting_and_color(self):
        result, summary, _, frames = self.run_case(
            "import os,time; os.write(1,b'\\x1b[36mRunning'); time.sleep(.12); "
            "os.write(1,b'\\r\\x1b[2K\\x1b[32mDone\\x1b[0m')")
        self.assertEqual(result.returncode, 0)
        self.assertEqual(frames[-1]["lines"][0].strip(), "Done")
        self.assertTrue(any(frame["lines"][0].strip() == "Running" for frame in frames))
        done_cells = [cell for cell in frames[-1]["cells"] if cell["data"] != " "]
        self.assertTrue(all(cell["fg"] == "green" for cell in done_cells))
        self.assertGreater(summary["max_idle_gap_seconds"], .1)

    def test_scripted_input_waits_for_observed_prompt(self):
        result, summary, output, _ = self.run_case(
            "answer=input('Proceed? '); print('accepted' if answer == 'yes' else 'refused')",
            plan=[{"wait_for": "Proceed?", "send": "yes\n"}])
        self.assertEqual(result.returncode, 0)
        self.assertEqual(summary["input_actions_sent"], 1)
        self.assertIn("accepted", (output / "transcript.txt").read_text())
        events = (output / "input-events.jsonl").read_text()
        self.assertNotIn("yes", events)

    def test_dim_survives_color_changes_and_resets_independently(self):
        result, _, _, frames = self.run_case(
            "import os; os.write(1, b'\\x1b[2mA\\x1b[38;2;0;22;2mB\\x1b[1mC'"
            "b'\\x1b[22mD\\x1b[2;0mE\\x1b[0;2mF\\x1b[0mG')")
        self.assertEqual(result.returncode, 0)
        cells = {cell["data"]: cell for cell in frames[-1]["cells"] if cell["data"] != " "}
        self.assertEqual({letter: cells[letter]["dim"] for letter in "ABCDEFG"},
                         dict(zip("ABCDEFG", [True, True, True, False, False, True, False])))
        self.assertEqual(cells["B"]["fg"], "001602")
        self.assertTrue(cells["C"]["bold"])
        self.assertFalse(cells["D"]["bold"])

    def test_saved_cursor_and_screen_reset_preserve_intensity_semantics(self):
        result, _, _, frames = self.run_case(
            "import os; os.write(1, b'\\x1b[2m\\x1b7\\x1b[22mN\\x1b8D\\x1b' b'cR')")
        self.assertEqual(result.returncode, 0)
        cells = [cell for cell in frames[-1]["cells"] if cell["data"] != " "]
        self.assertEqual([(cell["data"], cell["dim"]) for cell in cells], [("R", False)])

    def test_unsent_input_plan_does_not_report_success(self):
        result, summary, _, _ = self.run_case(
            "print('finished without prompt')", plan=[{"wait_for": "Proceed?", "send": "yes\n"}])
        self.assertEqual(result.returncode, 125)
        self.assertEqual(summary["child_exit_code"], 0)
        self.assertEqual(summary["input_actions_sent"], 0)

    def test_terminal_dimensions_are_applied_before_exec(self):
        result, _, output, _ = self.run_case(
            "import os; print(os.isatty(0), os.isatty(1), os.get_terminal_size().columns, os.get_terminal_size().lines)",
            "--columns", "87", "--rows", "23")
        self.assertEqual(result.returncode, 0)
        self.assertIn("True True 87 23", (output / "transcript.txt").read_text())

    def test_idle_timeout_keeps_actual_child_status(self):
        result, summary, _, _ = self.run_case(
            "import time; print('waiting', flush=True); time.sleep(10)", "--idle-timeout", ".15")
        self.assertEqual(result.returncode, 124)
        self.assertEqual(summary["termination"], "idle_timeout")
        self.assertEqual(summary["child_exit_code"], 143)

    def test_wall_timeout_works_when_output_continues(self):
        result, summary, _, _ = self.run_case(
            "import time\nwhile True:\n print('tick', flush=True); time.sleep(.04)", "--timeout", ".2")
        self.assertEqual(result.returncode, 124)
        self.assertEqual(summary["termination"], "timeout")

    def test_first_output_delay_includes_initial_silence(self):
        result, summary, _, _ = self.run_case("import time; time.sleep(.2); print('ready')")
        self.assertEqual(result.returncode, 0)
        self.assertGreaterEqual(summary["first_output_seconds"], .18)
        self.assertGreaterEqual(summary["max_idle_gap_seconds"], .18)

    def test_existing_recording_is_not_overwritten(self):
        with tempfile.TemporaryDirectory() as root:
            result = subprocess.run([sys.executable, str(CAPTURE), "--output-dir", root,
                                     "--candidate", "fixture", "--label", "existing",
                                     "--", sys.executable, "-c", "print('must not run')"],
                                    capture_output=True, timeout=5)
            self.assertEqual(result.returncode, 125)
            self.assertFalse((Path(root) / "raw.bin").exists())


if __name__ == "__main__":
    unittest.main()
