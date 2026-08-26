from pathlib import Path
import random
import argparse

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
TEMPLATE_PATH = ROOT / 'assets' / 'template.png'
OUTPUT_ROOT = ROOT / 'storage' / 'dummy_data'

SIDES = ['front', 'back']
CLASSIFICATIONS = ['regular', 'suspicious']
GROUPS = ['group_1', 'group_2', 'group_3', 'ungroupable']


def build_output_path(side: str, classification: str, group: str, index: int) -> Path:
    target_dir = OUTPUT_ROOT / side / classification / group
    target_dir.mkdir(parents=True, exist_ok=True)
    return target_dir / f'{side}_{classification}_{group}_{index + 1}.png'


def apply_variation(template_image: Image.Image, side: str, classification: str, group: str, index: int) -> Image.Image:
    image = template_image.convert('RGBA')
    width, height = image.size
    draw = ImageDraw.Draw(image)

    overlay_color = random.choice([
        (18, 94, 166, 120),
        (220, 38, 38, 110),
        (22, 163, 74, 110),
        (217, 119, 6, 110),
    ])
    draw.rectangle([(24, 24), (width - 24, height - 24)], fill=overlay_color)

    accent_color = random.choice(['#2563eb', '#dc2626', '#16a34a', '#d97706'])
    draw.rectangle([(48, 52), (width - 48, 96)], fill=accent_color)
    draw.rectangle([(54, 126), (width - 54, 176)], outline=(255, 255, 255, 220), width=3)
    draw.rectangle([(54, 202), (width - 54, 252)], outline=(255, 255, 255, 220), width=3)
    draw.rectangle([(54, 278), (width - 54, 328)], outline=(255, 255, 255, 220), width=3)

    label = f'{side.upper()} • {classification.upper()} • {group.upper()}'
    draw.text((70, 60), 'CHEQUE TEMPLATE', fill=(255, 255, 255, 255))
    draw.text((70, 108), label, fill=(255, 255, 255, 255))
    draw.text((70, 152), f'Variation #{index + 1}', fill=(255, 255, 255, 220))
    draw.text((70, 228), f'Amount: ${random.randint(100, 4000)}', fill=(255, 255, 255, 220))
    draw.text((70, 304), f'Batch: {group}', fill=(255, 255, 255, 220))

    if random.random() > 0.5:
        draw.ellipse((width - 180, 64, width - 96, 148), fill=(255, 255, 255, 180))

    return image


def generate(output_root: Path | None = None, per_group: int = 2) -> list[Path]:
    template_path = TEMPLATE_PATH
    if not template_path.exists():
        raise FileNotFoundError(f'Template not found at {template_path}')

    output_root = output_root or OUTPUT_ROOT
    output_root.mkdir(parents=True, exist_ok=True)

    generated_paths: list[Path] = []
    with Image.open(template_path) as template_image:
        for side in SIDES:
            for classification in CLASSIFICATIONS:
                for group in GROUPS:
                    for index in range(per_group):
                        output_path = build_output_path(side, classification, group, index)
                        image = apply_variation(template_image, side, classification, group, index)
                        image.save(output_path)
                        generated_paths.append(output_path)
                        print(f'Generated {output_path}')

    return generated_paths


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Generate cheque images from the project template image into the app image root.')
    parser.add_argument('--root', type=Path, default=None, help='Override the output directory for generated images')
    parser.add_argument('--per-group', type=int, default=2, help='How many images to generate per side/classification/group combination')
    args = parser.parse_args()

    generate(output_root=args.root, per_group=args.per_group)
